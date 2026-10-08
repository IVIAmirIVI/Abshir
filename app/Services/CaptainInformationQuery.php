<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\User;
use App\Support\CaptainSelfView;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Read-only queries with distinct self/global and administrative/Branch disclosure scopes. */
class CaptainInformationQuery
{
    public function __construct(private readonly FinancialBalanceQueryService $balances) {}

    public function canView(User $actor): bool
    {
        return $actor->can('captains.view') && ($actor->isSuperAdmin()
            || ! $actor->roles()->where('code', 'CAPTAIN')->exists());
    }

    public function snapshot(User $actor, Captain $captain, string $period = '7days', ?int $branchId = null): array
    {
        abort_unless($this->canView($actor) && $actor->canAccessCaptain($captain), 403);
        abort_unless(array_key_exists($period, OperationalDashboardQuery::PERIODS), 422);
        $branches = $actor->availableBranches()->orderBy('name')->get(['id', 'name']);

        return $this->buildSnapshot($captain, $branches, $period, $branchId,
            $actor->can('captain_financials.view'), $actor->can('orders.view'), $actor->isSuperAdmin(), false);
    }

    public function selfSnapshot(User $actor, string $period = '7days', ?int $branchId = null): array
    {
        Gate::forUser($actor)->authorize(CaptainSelfView::ABILITY);
        $captain = app(CaptainSelfView::class)->resolve($actor);
        abort_unless($captain !== null, 403);
        abort_unless(array_key_exists($period, OperationalDashboardQuery::PERIODS), 422);
        // A Captain's own historical information survives transfers and inactive Branches.
        // No user_branches assignment or current employment inference is needed for self-read.
        $ledgerBranches = $this->movements($captain)->select('t.branch_id');
        $assignedBranches = DB::table('order_captains as a')->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.captain_id', $captain->id)->select('o.branch_id');
        $branches = Branch::query()->where(fn ($q) => $q->where('id', $captain->home_branch_id)
            ->orWhereIn('id', $ledgerBranches)->orWhereIn('id', $assignedBranches))
            ->orderBy('name')->get(['id', 'name']);

        return $this->buildSnapshot($captain, $branches, $period, $branchId, true, true, true, true)
            + ['captain' => $captain];
    }

    private function buildSnapshot(Captain $captain, Collection $branches, string $period, ?int $branchId,
        bool $financial, bool $performance, bool $globalBalances, bool $selfView): array
    {
        abort_if($branchId !== null && ! $branches->contains('id', $branchId), 403);
        $branchIds = $branchId === null ? $branches->modelKeys() : [$branchId];
        $rows = [];
        $balanceRows = [];
        $earnings = collect();
        $movements = collect();
        if ($financial) {
            if ($globalBalances) {
                // Period and Branch filters apply only to activity, never to global accounts.
                $valuesByCurrency = $this->balances->captainBalancesByCurrency($captain);
                foreach (Currency::query()->whereKey(array_keys($valuesByCurrency))->orderBy('code')->get() as $currency) {
                    $balanceRows[] = ['currency' => $currency, 'values' => $valuesByCurrency[$currency->id]];
                }
            } else {
                // Existing branch-read authority is not permission to disclose global accounts.
                // These are attributed movement subtotals, explicitly labeled as such in the UI.
                $currencyIds = $this->movements($captain, $branchIds)->distinct()->pluck('a.currency_id');
                $currencies = Currency::query()->whereKey($currencyIds)->orderBy('code')->get();
                $branchesById = $branches->keyBy('id');
                foreach ($currencies as $currency) {
                    foreach ($this->balances->captainBalancesByBranch($captain, $currency, $branchIds) as $id => $values) {
                        $rows[] = ['branch' => $branchesById[$id], 'currency' => $currency, 'values' => $values];
                    }
                }
            }
            $earnings = $this->entitlements($captain, $branchIds)->map(function ($row) {
                $row->amount = (string) BigDecimal::of((string) $row->amount)->toScale(4);

                return $row;
            });
            $movements = $this->movements($captain, $branchIds)
                ->join('branches as b', 'b.id', '=', 't.branch_id')
                ->join('currencies as c', 'c.id', '=', 'a.currency_id')
                ->orderByDesc('t.occurred_at')->orderByDesc('e.id')->limit(25)
                ->get(['e.id', 'e.amount', 'e.direction', 'a.account_kind', 't.event_type', 't.source_type',
                    't.source_id', 't.occurred_at', 't.reversal_of_transaction_id', 'b.name as branch_name', 'c.code as currency_code']);
            foreach ($movements as $movement) {
                $movement->amount = (string) BigDecimal::of((string) $movement->amount)->toScale(4);
            }
        }

        return compact('branches', 'period', 'branchId', 'financial', 'performance', 'globalBalances', 'selfView', 'balanceRows', 'rows', 'earnings', 'movements')
            + ['activity' => $performance ? $this->activity($captain, $branchIds, $period) : null];
    }

    private function movements(Captain $captain, ?array $branchIds = null): Builder
    {
        return DB::table('financial_entries as e')
            ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->where('o.owner_type', 'CAPTAIN')->where('o.captain_id', $captain->id)
            ->where('t.status', 'POSTED')->when($branchIds !== null, fn (Builder $q) => $q->whereIn('t.branch_id', $branchIds));
    }

    /** Current recognized source basis, not payout or customer delivery charge. */
    private function entitlements(Captain $captain, array $branchIds): Collection
    {
        $original = DB::table('order_financial_source_snapshot_lines as l')
            ->join('order_financial_source_snapshots as s', 's.id', '=', 'l.order_financial_source_snapshot_id')
            ->join('invoices as i', 'i.id', '=', 's.invoice_id')
            ->join('invoice_collections as c', 'c.invoice_id', '=', 'i.id')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('l.captain_id', $captain->id)->whereIn('s.branch_id', $branchIds)
            ->whereNull('i.superseded_at')->where('i.status', 'ISSUED')->where('o.operational_status', 'DELIVERED')
            ->selectRaw('s.branch_id, s.currency_id, l.captain_entitlement AS amount');
        // A completed correction replaces the original basis. Its immutable allocations
        // preserve Captain attribution; cancel-and-refund has no allocations.
        $corrected = DB::table('invoice_correction_financial_snapshots as s')
            ->join('invoice_financial_corrections as f', 'f.id', '=', 's.invoice_financial_correction_id')
            ->join('invoices as i', 'i.id', '=', 'f.replacement_invoice_id')
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            ->crossJoin(DB::raw("jsonb_array_elements(s.payload->'allocations') AS allocation"))
            ->whereRaw("(allocation->>'captain_id')::bigint = ?", [$captain->id])
            ->whereIn('s.branch_id', $branchIds)->where('f.state', 'COMPLETED')
            ->whereNull('i.superseded_at')->where('i.status', 'ISSUED')->where('o.operational_status', 'DELIVERED')
            ->selectRaw("s.branch_id, s.currency_id, (allocation->>'captain_entitlement')::numeric AS amount");

        return DB::query()->fromSub($original->unionAll($corrected), 'entitlements')
            ->join('branches as b', 'b.id', '=', 'entitlements.branch_id')
            ->join('currencies as c', 'c.id', '=', 'entitlements.currency_id')
            ->groupBy('b.id', 'b.name', 'c.id', 'c.code')
            ->selectRaw('b.name as branch_name, c.code as currency_code, SUM(entitlements.amount) AS amount')
            ->orderBy('b.name')->orderBy('c.code')->get();
    }

    private function activity(Captain $captain, array $branchIds, string $period): array
    {
        $today = CarbonImmutable::now(OperationalDashboardQuery::TIMEZONE)->startOfDay();
        $start = $today->subDays(OperationalDashboardQuery::PERIODS[$period] - 1);
        $end = $today->addDay();
        $assignments = DB::table('order_captains as a')->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.captain_id', $captain->id)->whereIn('o.branch_id', $branchIds);
        $assigned = (clone $assignments)->where('a.assigned_at', '>=', $start->utc()->toIso8601String())
            ->where('a.assigned_at', '<', $end->utc()->toIso8601String())->distinct()->count('o.id');
        // Participation is evaluated at the outcome instant, not from today's active
        // assignments; replacements before delivery are not credited for delivery.
        $outcomes = DB::table('order_status_history as h')->join('orders as o', 'o.id', '=', 'h.order_id')
            ->whereIn('o.branch_id', $branchIds)->whereColumn('h.new_status', 'o.operational_status')
            ->whereIn('h.new_status', ['DELIVERED', 'CANCELLED'])
            ->where('h.changed_at', '>=', $start->utc()->toIso8601String())
            ->where('h.changed_at', '<', $end->utc()->toIso8601String())
            ->whereExists(function (Builder $q) use ($captain): void {
                $q->selectRaw('1')->from('order_captains as a')->whereColumn('a.order_id', 'o.id')
                    ->where('a.captain_id', $captain->id)->whereColumn('a.assigned_at', '<=', 'h.changed_at')
                    ->where(fn (Builder $dates) => $dates->whereNull('a.removed_at')->orWhereColumn('a.removed_at', '>', 'h.changed_at'));
            });
        $counts = (clone $outcomes)->selectRaw('h.new_status, COUNT(DISTINCT o.id) AS total')
            ->groupBy('h.new_status')->pluck('total', 'h.new_status');
        $daily = (clone $outcomes)->selectRaw('(h.changed_at AT TIME ZONE ?)::date AS day, h.new_status, COUNT(DISTINCT o.id) AS total', [OperationalDashboardQuery::TIMEZONE])
            ->groupBy('day', 'h.new_status')->get()->keyBy(fn ($row) => $row->day.'-'.$row->new_status);
        $trend = [];
        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $trend[] = ['date' => $date, 'delivered' => (int) ($daily[$date.'-DELIVERED']->total ?? 0),
                'cancelled' => (int) ($daily[$date.'-CANCELLED']->total ?? 0)];
        }

        return ['from' => $start->format('Y-m-d'), 'to' => $today->format('Y-m-d'),
            'metrics' => ['assigned' => $assigned, 'delivered' => (int) ($counts['DELIVERED'] ?? 0), 'cancelled' => (int) ($counts['CANCELLED'] ?? 0)],
            'trend' => $trend];
    }
}
