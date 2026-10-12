<?php

namespace App\Services;

use App\Models\Captain;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManagementReportQuery
{
    private array $readiness = [];

    public function canView(User $actor): bool
    {
        return app(OperationalDashboardQuery::class)->canView($actor);
    }

    public function snapshot(User $actor, array $filters): array
    {
        $context = $this->context($actor, $filters);

        return ['operations' => $this->operations($actor, $context['branches'], $context['start'], $context['end']),
            'financial' => $this->financial($actor, $context)];
    }

    public function context(User $actor, array $filters): array
    {
        abort_unless($this->canView($actor), 403);
        $filters = Validator::make($filters, [
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'min:1'], 'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
        ])->validate();
        $start = CarbonImmutable::parse($filters['from'], OperationalDashboardQuery::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($filters['to'], OperationalDashboardQuery::TIMEZONE)->startOfDay()->addDay();
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => __('reports.period_limit')]);
        }
        $branch = filled($filters['branch_id'] ?? null) ? (int) $filters['branch_id'] : null;
        abort_if($branch !== null && ! $actor->availableBranches()->whereKey($branch)->exists(), 403);
        $branches = $branch ? [$branch] : ($actor->isSuperAdmin() ? null : $actor->availableBranches()->pluck('branches.id')->all());
        $currency = filled($filters['currency_id'] ?? null) ? (int) $filters['currency_id'] : null;

        return compact('start', 'end', 'branches', 'currency', 'filters');
    }

    private function ready(array $requirements): bool
    {
        foreach ($requirements as $table => $columns) {
            $key = $table.':'.implode(',', $columns);
            $this->readiness[$key] ??= Schema::hasTable($table) && Schema::hasColumns($table, $columns);
            if (! $this->readiness[$key]) {
                return false;
            }
        }

        return true;
    }

    private function scope(Builder $query, string $branchColumn, ?array $branches): Builder
    {
        return $query->when($branches !== null, fn ($q) => $q->whereIn($branchColumn, $branches));
    }

    private function period(Builder $query, string $column, string $from, string $until): Builder
    {
        return $query->where($column, '>=', $from)->where($column, '<', $until);
    }

    public function orderSources(?array $branches, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        if (! $this->ready(['orders' => ['created_at', 'ordered_at', 'branch_id', 'operational_status'],
            'order_status_history' => ['order_id', 'new_status', 'changed_at'],
            'order_captains' => ['captain_id', 'order_id', 'removed_at'], 'order_shops' => ['order_id', 'shop_id']])) {
            return null;
        }
        $orders = $this->scope(DB::table('orders as o'), 'o.branch_id', $branches);
        $cohort = $this->period(clone $orders, 'o.created_at', $start->utc()->format('Y-m-d H:i:s'), $end->utc()->format('Y-m-d H:i:s'));
        $final = DB::table('order_status_history as history')
            ->whereIn('history.order_id', (clone $orders)->select('o.id'))
            ->whereIn('history.new_status', ['DELIVERED', 'CANCELLED'])
            ->groupBy('history.order_id', 'history.new_status')->selectRaw('history.order_id, history.new_status, MIN(history.changed_at) AS changed_at');
        $events = (clone $orders)->joinSub($final, 'h', fn ($join) => $join->on('h.order_id', '=', 'o.id')->on('h.new_status', '=', 'o.operational_status'));
        $events = $this->period($events, 'h.changed_at', $start->utc()->toIso8601String(), $end->utc()->toIso8601String());
        $assignments = DB::table('order_captains')->whereNull('removed_at')
            ->whereIn('order_id', (clone $orders)->select('o.id'))
            ->groupBy('order_id')->selectRaw('order_id, COUNT(DISTINCT captain_id) AS participants');

        return compact('orders', 'cohort', 'events', 'assignments');
    }

    public function participationSource(User $actor, Builder $cohort, string $type): Builder
    {
        if ($type === 'captains') {
            abort_unless($actor->can('captains.view'), 403);

            return (clone $cohort)->join('order_captains as a', 'a.order_id', '=', 'o.id')->whereNull('a.removed_at')
                ->join('captains as master', 'master.id', '=', 'a.captain_id')->join('users as person', 'person.id', '=', 'master.user_id')
                ->whereIn('a.captain_id', Captain::query()->accessibleTo($actor)->select('captains.id'))
                ->groupBy('a.captain_id')->selectRaw("a.captain_id AS id, MAX(person.name) AS name, COUNT(DISTINCT o.id) AS total, COUNT(DISTINCT o.id) FILTER(WHERE o.operational_status='DELIVERED') AS delivered")
                ->orderBy('a.captain_id');
        }
        abort_unless($type === 'shops' && $actor->can('shops.view'), 403);

        return (clone $cohort)->join('order_shops as s', 's.order_id', '=', 'o.id')->join('shops as master', 'master.id', '=', 's.shop_id')
            ->whereIn('master.id', Shop::query()->accessibleTo($actor)->select('shops.id'))
            ->groupBy('master.id', 'master.name')->selectRaw('master.id AS id, master.name AS name, COUNT(DISTINCT o.id) AS total')->orderBy('master.id');
    }

    private function operations(User $actor, ?array $branches, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sources = $this->orderSources($branches, $start, $end);
        if ($sources === null) {
            return ['available' => false];
        }
        ['cohort' => $cohort, 'events' => $events, 'assignments' => $assignments] = $sources;
        $outcomes = (clone $events)->selectRaw('h.new_status, COUNT(*) AS total')->groupBy('h.new_status')->pluck('total', 'h.new_status');
        $counts = (clone $cohort)->groupBy('o.operational_status')->selectRaw('o.operational_status, COUNT(*) AS total')->pluck('total', 'o.operational_status')->all();

        $sizes = (clone $cohort)->leftJoinSub($assignments, 'a', 'a.order_id', '=', 'o.id')
            ->selectRaw('COUNT(*) FILTER(WHERE a.participants=1) AS single, COUNT(*) FILTER(WHERE a.participants>=2) AS shared, COUNT(*) FILTER(WHERE a.participants IS NULL) AS unassigned')->first();
        $duration = (clone $events)->where('h.new_status', 'DELIVERED')->whereColumn('h.changed_at', '>=', 'o.ordered_at')
            ->selectRaw('COUNT(*) AS samples, ROUND(AVG(EXTRACT(EPOCH FROM (h.changed_at-o.ordered_at))/60),2)::text AS minutes')->first();
        $dailyCreated = (clone $cohort)->selectRaw("(o.created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date AS day, COUNT(*) AS total", [OperationalDashboardQuery::TIMEZONE])->groupBy('day')->pluck('total', 'day');
        $dailyEvents = (clone $events)->selectRaw('(h.changed_at AT TIME ZONE ?)::date AS day, h.new_status, COUNT(*) AS total', [OperationalDashboardQuery::TIMEZONE])->groupBy('day', 'h.new_status')->get()->keyBy(fn ($row) => $row->day.'-'.$row->new_status);
        $trend = [];
        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $trend[] = ['date' => $date, 'created' => (int) ($dailyCreated[$date] ?? 0),
                'delivered' => (int) ($dailyEvents[$date.'-DELIVERED']->total ?? 0), 'cancelled' => (int) ($dailyEvents[$date.'-CANCELLED']->total ?? 0)];
        }
        $captains = $actor->can('captains.view') ? $this->participationSource($actor, $cohort, 'captains')->paginate(15, ['*'], 'captainsPage') : null;
        $shops = $actor->can('shops.view') ? $this->participationSource($actor, $cohort, 'shops')->paginate(15, ['*'], 'shopsPage') : null;
        $versions = Schema::hasColumn('orders', 'accounting_contract_version')
            ? (clone $cohort)->groupBy('o.accounting_contract_version')->selectRaw('o.accounting_contract_version AS version, COUNT(*) AS total')->get()
            : collect([(object) ['version' => 1, 'total' => (clone $cohort)->count()]]);

        return ['available' => true, 'created' => (clone $cohort)->count(), 'delivered' => (int) ($outcomes['DELIVERED'] ?? 0),
            'cancelled' => (int) ($outcomes['CANCELLED'] ?? 0), 'single' => (int) $sizes->single, 'shared' => (int) $sizes->shared,
            'unassigned' => (int) $sizes->unassigned, 'duration' => $duration, 'statuses' => $counts, 'trend' => $trend,
            'captains' => $captains, 'shops' => $shops, 'versions' => $versions,
            'one_time_shops' => (clone $cohort)->join('order_shops as s', 's.order_id', '=', 'o.id')->whereNull('s.shop_id')->count()];
    }

    private function moneyRows(Builder $query, string $currency, array $expressions): array
    {
        $query->join('currencies as cur', 'cur.id', '=', $currency)->groupBy('cur.id', 'cur.code')->select('cur.id as currency_id', 'cur.code');
        foreach ($expressions as $label => $expression) {
            $query->selectRaw("COALESCE($expression,0)::text AS $label");
        }

        return $query->orderBy('cur.code')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function financialSource(User $actor, string $type, array $context): ?Builder
    {
        abort_unless($this->canView($actor), 403);
        $permissions = [
            'invoices' => ['invoices.view'], 'collections' => ['invoice_collections.view'],
            'balances' => ['captain_financials.view'], 'benefits' => ['captain_financials.view', 'captains.view'],
            'payouts' => ['captain_financials.view', 'captain_receivable_payouts.view'],
            'commissions' => ['shops.view', 'company_financials.view'],
        ];
        abort_unless(isset($permissions[$type]), 404);
        foreach ($permissions[$type] as $permission) {
            abort_unless($actor->can($permission), 403);
        }
        ['branches' => $branches, 'currency' => $currency, 'start' => $start, 'end' => $end] = $context;
        $from = $start->utc()->toIso8601String();
        $until = $end->utc()->toIso8601String();
        $requirements = match ($type) {
            'invoices' => ['invoices' => ['issued_at', 'superseded_at', 'status', 'grand_total', 'currency_id', 'order_id'], 'orders' => ['branch_id']],
            'collections' => ['invoice_collections' => ['collected_at', 'amount', 'currency_id', 'collection_method', 'order_id'], 'orders' => ['branch_id']],
            'payouts' => ['captain_receivable_payouts' => ['branch_id', 'currency_id', 'amount', 'occurred_at']],
            'commissions' => ['shop_commission_transactions' => ['account_id', 'amount', 'kind', 'occurred_at'], 'shop_commission_accounts' => ['shop_id', 'branch_id', 'currency_id'], 'shops' => ['area_id']],
            default => ['financial_entries' => ['amount', 'direction', 'financial_account_id', 'financial_transaction_id'],
                'financial_transactions' => ['status', 'occurred_at', 'branch_id', 'event_type', 'reversal_of_transaction_id'],
                'financial_accounts' => ['financial_owner_id', 'currency_id', 'account_kind'], 'financial_owners' => ['owner_type', 'captain_id']],
        };
        if ($type === 'benefits') {
            $requirements['captain_benefit_reviews'] = ['action', 'captain_benefit_request_id'];
            $requirements['captain_benefit_requests'] = ['proposal_key', 'captain_id'];
        }
        if (! $this->ready($requirements)) {
            return null;
        }
        if ($type === 'invoices') {
            $q = $this->scope(DB::table('invoices as i')->join('orders as o', 'o.id', '=', 'i.order_id'), 'o.branch_id', $branches)
                ->where('i.status', 'ISSUED')->whereNull('i.superseded_at')
                ->when($currency !== null, fn ($q) => $q->where('i.currency_id', $currency));

            return $this->period($q, 'i.issued_at', $from, $until);
        }
        if ($type === 'collections') {
            $q = $this->scope(DB::table('invoice_collections as c')->join('orders as o', 'o.id', '=', 'c.order_id'), 'o.branch_id', $branches)
                ->when($currency !== null, fn ($q) => $q->where('c.currency_id', $currency));

            return $this->period($q, 'c.collected_at', $from, $until);
        }
        if ($type === 'payouts') {
            $q = $this->scope(DB::table('captain_receivable_payouts as p'), 'p.branch_id', $branches)
                ->when($currency !== null, fn ($q) => $q->where('p.currency_id', $currency));

            return $this->period($q, 'p.occurred_at', $from, $until);
        }
        if ($type === 'commissions') {
            return $this->scope(DB::table('shop_commission_transactions as t')->join('shop_commission_accounts as a', 'a.id', '=', 't.account_id'), 'a.branch_id', $branches)
                ->whereIn('a.shop_id', Shop::query()->accessibleTo($actor)->select('shops.id'))
                ->when($currency !== null, fn ($q) => $q->where('a.currency_id', $currency))->where('t.occurred_at', '<', $until);
        }
        $q = $this->scope(DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')->join('financial_owners as owner', 'owner.id', '=', 'a.financial_owner_id'), 't.branch_id', $branches)
            ->where('owner.owner_type', 'CAPTAIN')->where('t.status', 'POSTED')
            ->when($currency !== null, fn ($q) => $q->where('a.currency_id', $currency))->where('t.occurred_at', '<', $until);
        if ($type === 'benefits') {
            $q->leftJoin('financial_transactions as original', 'original.id', '=', 't.reversal_of_transaction_id')
                ->where('a.account_kind', 'CAPTAIN_COMPANY_RECEIVABLE')->where('t.occurred_at', '>=', $from)
                ->where(function ($q): void {
                    $q->whereIn('t.event_type', ['CAPTAIN_BONUS_DUE', 'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE'])
                        ->orWhere(fn ($q) => $q->where('t.event_type', 'CAPTAIN_BENEFIT_REVERSED')->whereIn('original.event_type', ['CAPTAIN_BONUS_DUE', 'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE']));
                });
        }

        return $q;
    }

    private function financial(User $actor, array $context): array
    {
        $groups = [];
        $permissions = ['invoices' => ['invoices.view'], 'collections' => ['invoice_collections.view'],
            'balances' => ['captain_financials.view'], 'benefits' => ['captain_financials.view', 'captains.view'],
            'payouts' => ['captain_financials.view', 'captain_receivable_payouts.view'], 'commissions' => ['shops.view', 'company_financials.view']];
        foreach ($permissions as $type => $required) {
            if (collect($required)->contains(fn ($p) => ! $actor->can($p))) {
                continue;
            }
            $q = $this->financialSource($actor, $type, $context);
            $groups[$type] = ['available' => $q !== null, 'rows' => []];
            if ($q === null) {
                continue;
            }
            $rows = match ($type) {
                'invoices' => $this->moneyRows($q, 'i.currency_id', ['issued' => 'SUM(i.grand_total)']),
                'collections' => $this->moneyRows(clone $q, 'c.currency_id', ['cash' => "SUM(c.amount) FILTER(WHERE c.collection_method='CASH')", 'sham_cash' => "SUM(c.amount) FILTER(WHERE c.collection_method='SHAM_CASH')"]),
                'balances' => [],
                'benefits' => $this->moneyRows($q, 'a.currency_id', [
                    'bonus' => "SUM(CASE WHEN COALESCE(original.event_type,t.event_type)='CAPTAIN_BONUS_DUE' THEN CASE WHEN e.direction='INCREASE' THEN e.amount ELSE -e.amount END ELSE 0 END)",
                    'expense' => "SUM(CASE WHEN COALESCE(original.event_type,t.event_type)='CAPTAIN_EXPENSE_REIMBURSEMENT_DUE' THEN CASE WHEN e.direction='INCREASE' THEN e.amount ELSE -e.amount END ELSE 0 END)",
                ]),
                'payouts' => $this->moneyRows($q, 'p.currency_id', ['paid' => 'SUM(p.amount)']),
                'commissions' => [],
            };
            if ($type === 'collections') {
                $version = Schema::hasColumn('orders', 'accounting_contract_version') ? 'o.accounting_contract_version' : '1';
                $groups[$type]['versions'] = $q->join('currencies as cur', 'cur.id', '=', 'c.currency_id')->selectRaw("$version AS version, cur.code, c.collection_method, SUM(c.amount)::text AS amount")
                    ->groupByRaw("$version, cur.code, c.collection_method")->orderBy('cur.code')->get()->map(fn ($r) => (array) $r)->all();
            }
            if ($type === 'balances') {
                $balances = $q->groupBy('a.currency_id', 'a.account_kind')->selectRaw("a.currency_id, a.account_kind, SUM(CASE WHEN e.direction='INCREASE' THEN e.amount ELSE -e.amount END)::text AS amount")->get();
                $codes = DB::table('currencies')->pluck('code', 'id');
                foreach ($balances as $row) {
                    $rows[$row->currency_id] ??= ['currency_id' => $row->currency_id, 'code' => $codes[$row->currency_id],
                        'CAPTAIN_DEPOSIT' => '0', 'CAPTAIN_COMPANY_RECEIVABLE' => '0', 'CAPTAIN_CASH_CUSTODY' => '0'];
                    $rows[$row->currency_id][$row->account_kind] = $row->amount;
                }
                $rows = array_values($rows);
            }
            if ($type === 'commissions') {
                $accounts = (clone $q)->groupBy('a.id', 'a.currency_id')->selectRaw("a.id, a.currency_id, SUM(CASE WHEN t.kind='EARNING' THEN t.amount ELSE -t.amount END) AS outstanding");
                $balances = $this->moneyRows(DB::query()->fromSub($accounts, 'a'), 'a.currency_id', ['outstanding' => 'SUM(GREATEST(a.outstanding,0))', 'credit' => 'SUM(GREATEST(-a.outstanding,0))']);
                $movements = $this->moneyRows($q->where('t.occurred_at', '>=', $context['start']->utc()->toIso8601String()), 'a.currency_id',
                    ['earned' => "SUM(t.amount) FILTER(WHERE t.kind='EARNING')", 'reversed' => "SUM(t.amount) FILTER(WHERE t.kind='REVERSAL')", 'paid' => "SUM(t.amount) FILTER(WHERE t.kind='PAYMENT')"]);
                $rows = collect($balances)->keyBy('currency_id');
                foreach ($movements as $row) {
                    $rows[$row['currency_id']] = array_replace($rows[$row['currency_id']] ?? [], $row);
                }
                $rows = $rows->values()->all();
            }
            $groups[$type]['rows'] = $rows;
        }

        return $groups;
    }
}
