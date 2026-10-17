<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManagementReportDetailQuery
{
    public const TYPES = ['created', 'delivered', 'cancelled', 'single', 'shared', 'unassigned', 'captains', 'shops',
        'invoices', 'collections', 'balances', 'ledger', 'payouts', 'commissions', 'commission_balances', 'benefits'];

    public function __construct(private ManagementReportQuery $reports) {}

    /** Both the paginated page and CSV consume this exact authorized projection. */
    public function report(User $actor, string $type, array $filters): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $context = $this->reports->context($actor, $filters);
        $columns = $this->columns($type);
        $query = in_array($type, ['created', 'delivered', 'cancelled', 'single', 'shared', 'unassigned', 'captains', 'shops'], true)
            ? $this->operational($actor, $type, $context) : $this->financial($actor, $type, $context);
        // Stable ordering includes every grouping key; chunk offsets must not duplicate tied rows.
        if ($query !== null) {
            $query = DB::query()->fromSub($query->reorder(), 'report_rows')->orderBy('id');
            if ($type === 'balances') {
                $query->orderBy('branch_id')->orderBy('currency')->orderBy('account_kind');
            }
        }

        return ['query' => $query, 'columns' => $columns, 'filters' => $context['filters'], 'type' => $type,
            'can_open_invoice' => $type !== 'collections' || $actor->can('invoices.view'),
            'help' => match ($type) {
                'captains', 'shops' => 'participation_help',
                'created', 'delivered', 'cancelled', 'single', 'shared', 'unassigned' => 'operational_help',
                'ledger' => 'help.balances', 'commission_balances' => 'help.commissions', default => 'help.'.$type,
            },
            'definition' => in_array($type, ['balances', 'ledger', 'commission_balances'], true) ? 'as_of' : 'flow',
            'source' => match ($type) {
                'captains', 'shops' => 'participation',
                'created', 'single', 'shared', 'unassigned' => 'orders',
                'delivered', 'cancelled' => 'history',
                'invoices' => 'invoices', 'collections' => 'collections', 'payouts' => 'payouts',
                'commissions', 'commission_balances' => 'commissions', default => 'ledger',
            }];
    }

    private function operational(User $actor, string $type, array $context): ?Builder
    {
        if (in_array($type, ['captains', 'shops'], true)) {
            abort_unless($actor->can($type === 'captains' ? 'captains.view' : 'shops.view'), 403);
        }
        $sources = $this->reports->orderSources($context['branches'], $context['start'], $context['end']);
        if ($sources === null) {
            return null;
        }
        if (in_array($type, ['captains', 'shops'], true)) {
            return $this->reports->participationSource($actor, $sources['cohort'], $type);
        }
        $final = in_array($type, ['delivered', 'cancelled'], true);
        $q = clone $sources[$final ? 'events' : 'cohort'];
        if ($final) {
            $q->where('h.new_status', strtoupper($type));
        }
        if (in_array($type, ['single', 'shared', 'unassigned'], true)) {
            $q->leftJoinSub($sources['assignments'], 'assignments', 'assignments.order_id', '=', 'o.id');
            match ($type) {
                'single' => $q->where('assignments.participants', 1),
                'shared' => $q->where('assignments.participants', '>=', 2),
                'unassigned' => $q->whereNull('assignments.participants'),
            };
        }
        $q->select('o.id', 'o.order_number', 'o.branch_id', 'o.operational_status as status', 'o.created_at', 'o.ordered_at');
        $q->selectRaw($this->version().' AS version');
        if ($final) {
            $q->addSelect('h.changed_at as finalized_at');
        }

        return $q;
    }

    private function version(): string
    {
        return Schema::hasColumn('orders', 'accounting_contract_version') ? 'o.accounting_contract_version' : '1';
    }

    private function financial(User $actor, string $type, array $context): ?Builder
    {
        $sourceType = match ($type) {
            'ledger' => 'balances', 'commission_balances' => 'commissions', default => $type
        };
        $q = $this->reports->financialSource($actor, $sourceType, $context);
        if ($q === null) {
            return null;
        }
        if ($type === 'invoices') {
            return $q->join('currencies as cur', 'cur.id', '=', 'i.currency_id')
                ->select('i.id', 'i.order_id', 'o.branch_id', 'i.issued_at', 'cur.code as currency')->selectRaw('i.grand_total::text AS amount, '.$this->version().' AS version');
        }
        if ($type === 'collections') {
            return $q->join('currencies as cur', 'cur.id', '=', 'c.currency_id')
                ->select('c.id', 'c.invoice_id', 'c.order_id', 'o.branch_id', 'c.captain_id', 'c.collection_method', 'c.collected_at', 'cur.code as currency')
                ->selectRaw('c.amount::text AS amount, '.$this->version().' AS version');
        }
        if ($type === 'payouts') {
            return $q->join('currencies as cur', 'cur.id', '=', 'p.currency_id')
                ->select('p.id', 'p.captain_id', 'p.branch_id', 'p.occurred_at', 'cur.code as currency')->selectRaw('p.amount::text AS amount');
        }
        if ($type === 'commissions') {
            return $q->join('currencies as cur', 'cur.id', '=', 'a.currency_id')
                ->where('t.occurred_at', '>=', $context['start']->utc()->toIso8601String())
                ->select('t.id', 'a.shop_id', 'a.branch_id', 't.account_id', 't.kind', 't.line_id', 't.occurred_at', 'cur.code as currency')
                ->selectRaw('t.amount::text AS amount');
        }
        if ($type === 'commission_balances') {
            return $q->join('currencies as cur', 'cur.id', '=', 'a.currency_id')
                ->groupBy('a.id', 'a.shop_id', 'a.branch_id', 'cur.code')
                ->select('a.id', 'a.shop_id', 'a.branch_id', 'cur.code as currency')
                ->selectRaw("GREATEST(SUM(CASE WHEN t.kind='EARNING' THEN t.amount ELSE -t.amount END),0)::text AS outstanding,
                    GREATEST(-SUM(CASE WHEN t.kind='EARNING' THEN t.amount ELSE -t.amount END),0)::text AS credit");
        }
        $q->join('currencies as cur', 'cur.id', '=', 'a.currency_id');
        $signed = "CASE WHEN e.direction='INCREASE' THEN e.amount ELSE -e.amount END";
        if ($type === 'balances') {
            return $q->groupBy('owner.captain_id', 't.branch_id', 'cur.id', 'cur.code', 'a.account_kind')
                ->select('owner.captain_id as id', 't.branch_id', 'cur.id as currency_id', 'cur.code as currency', 'a.account_kind')
                ->selectRaw("SUM($signed)::text AS amount");
        }
        $q->select('e.id', 'owner.captain_id', 't.branch_id', 't.id as transaction_id', 't.event_type', 't.source_type', 't.source_id', 't.occurred_at', 'a.account_kind', 'cur.code as currency')
            ->selectRaw("($signed)::text AS amount");
        if ($type === 'benefits') {
            // Text identity join avoids casting arbitrary polymorphic source IDs to bigint.
            $q->join('captain_benefit_reviews as review', fn ($join) => $join
                ->whereRaw("review.id::text = CASE WHEN t.event_type='CAPTAIN_BENEFIT_REVERSED' THEN original.source_id ELSE t.source_id END"))
                ->join('captain_benefit_requests as request', 'request.id', '=', 'review.captain_benefit_request_id')
                ->addSelect('request.id as proposal_id');
        }

        return $q;
    }

    private function columns(string $type): array
    {
        $fields = match ($type) {
            'captains' => ['id', 'name', 'total', 'delivered'], 'shops' => ['id', 'name', 'total'],
            'invoices' => ['id', 'order_id', 'branch_id', 'version', 'issued_at', 'currency', 'amount'],
            'collections' => ['id', 'invoice_id', 'order_id', 'branch_id', 'version', 'captain_id', 'collection_method', 'collected_at', 'currency', 'amount'],
            'balances' => ['id', 'branch_id', 'currency', 'account_kind', 'amount'],
            'payouts' => ['id', 'captain_id', 'branch_id', 'occurred_at', 'currency', 'amount'],
            'commissions' => ['id', 'shop_id', 'branch_id', 'account_id', 'kind', 'line_id', 'occurred_at', 'currency', 'amount'],
            'commission_balances' => ['id', 'shop_id', 'branch_id', 'currency', 'outstanding', 'credit'],
            'benefits', 'ledger' => ['id', 'captain_id', 'branch_id', 'transaction_id', 'event_type', 'source_type', 'source_id', 'occurred_at', 'account_kind', 'currency', 'amount'],
            'delivered', 'cancelled' => ['id', 'order_number', 'branch_id', 'status', 'version', 'created_at', 'ordered_at', 'finalized_at'],
            default => ['id', 'order_number', 'branch_id', 'status', 'version', 'created_at', 'ordered_at'],
        };

        return array_fill_keys($fields, null);
    }

    public function cell(object $row, string $field): string
    {
        $value = $row->{$field} ?? '';
        if ($value !== '' && in_array($field, ['created_at', 'ordered_at', 'finalized_at', 'issued_at', 'collected_at', 'occurred_at'], true)) {
            return CarbonImmutable::parse($value, 'UTC')->setTimezone(OperationalDashboardQuery::TIMEZONE)->format('Y-m-d H:i:sP');
        }

        return (string) $value;
    }

    public function sourceLink(string $type, object $row, bool $canOpenInvoice = false): ?string
    {
        // Destination controllers reauthorize the requested source; no private URLs are generated.
        return match ($type) {
            'created', 'delivered', 'cancelled', 'single', 'shared', 'unassigned' => route('orders.show', $row->id),
            'captains' => route('captains.show', $row->id), 'shops' => route('shops.show', $row->id),
            'invoices' => route('orders.invoices.show', ['order' => $row->order_id, 'invoice' => $row->id]),
            'collections' => $canOpenInvoice ? route('orders.invoices.show', ['order' => $row->order_id, 'invoice' => $row->invoice_id]) : null,
            'balances' => route('captains.financials.show', ['captain' => $row->id, 'branch_id' => $row->branch_id, 'currency_id' => $row->currency_id ?? null]),
            'payouts', 'ledger' => route('captains.financials.show', $row->captain_id),
            'benefits' => route('captains.requests.show', ['captain' => $row->captain_id, 'proposal' => $row->proposal_id]),
            'commissions', 'commission_balances' => route('shops.commission-account', $row->shop_id),
        };
    }
}
