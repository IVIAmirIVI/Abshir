<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialOwnerType;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialEntry;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialBalanceQueryService
{
    public function balance(FinancialAccount $account): string
    {
        $value = DB::table('financial_entries')
            ->where('financial_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE -amount END), 0) AS balance', [FinancialEntryDirection::Increase->value])
            ->value('balance');

        return (string) BigDecimal::of((string) $value)->toScale(4);
    }

    public function captainBalances(Captain $captain, Currency $currency): array
    {
        $balances = collect(FinancialAccountKind::cases())->mapWithKeys(fn (FinancialAccountKind $kind): array => [$kind->value => '0.0000'])->all();
        $ownerId = DB::table('financial_owners')
            ->where('owner_type', FinancialOwnerType::Captain->value)
            ->where('captain_id', $captain->id)
            ->value('id');
        if (! $ownerId) {
            return $balances;
        }

        FinancialAccount::query()
            ->where('financial_owner_id', $ownerId)
            ->where('currency_id', $currency->id)
            ->get()
            ->each(function (FinancialAccount $account) use (&$balances): void {
                $balances[$account->account_kind->value] = $this->balance($account);
            });

        return $balances;
    }

    /** Global account balances: Currency ID => separate account kinds, across all Branch history. */
    public function captainBalancesByCurrency(Captain $captain): array
    {
        $rows = DB::table('financial_accounts as a')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->leftJoin('financial_entries as e', 'e.financial_account_id', '=', 'a.id')
            ->where('o.owner_type', FinancialOwnerType::Captain->value)->where('o.captain_id', $captain->id)
            ->groupBy('a.currency_id', 'a.account_kind')
            ->selectRaw('a.currency_id, a.account_kind, COALESCE(SUM(CASE WHEN e.direction = ? THEN e.amount ELSE -e.amount END), 0) AS balance', [FinancialEntryDirection::Increase->value])
            ->get();
        $balances = [];
        foreach ($rows as $row) {
            $balances[$row->currency_id] ??= collect(FinancialAccountKind::cases())
                ->mapWithKeys(fn (FinancialAccountKind $kind): array => [$kind->value => '0.0000'])->all();
            $balances[$row->currency_id][$row->account_kind] = (string) BigDecimal::of((string) $row->balance)->toScale(4);
        }

        return $balances;
    }

    public function captainBranchBalance(Captain $captain, Branch $branch, Currency $currency, FinancialAccountKind $kind): string
    {
        $value = DB::table('financial_entries as e')
            ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->where('o.owner_type', FinancialOwnerType::Captain->value)
            ->where('o.captain_id', $captain->id)
            ->where('a.currency_id', $currency->id)
            ->where('a.account_kind', $kind->value)
            ->where('t.branch_id', $branch->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN e.direction = ? THEN e.amount ELSE -e.amount END), 0) AS balance', [FinancialEntryDirection::Increase->value])
            ->value('balance');

        return (string) BigDecimal::of((string) $value)->toScale(4);
    }

    /** @return array<int, array<string, string>> Branch ID => account-kind balances. */
    /** @param array<int, int>|null $branchIds Null permits all Branches for an authorized Super Admin. */
    public function captainBalancesByBranch(Captain $captain, Currency $currency, ?array $branchIds = null): array
    {
        if ($branchIds === []) {
            return [];
        }
        $query = DB::table('financial_entries as e')
            ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->where('o.owner_type', FinancialOwnerType::Captain->value)
            ->where('o.captain_id', $captain->id)
            ->where('a.currency_id', $currency->id)
            ->whereNotNull('t.branch_id');
        if ($branchIds !== null) {
            $query->whereIn('t.branch_id', $branchIds);
        }
        $rows = $query
            ->groupBy('t.branch_id', 'a.account_kind')
            ->selectRaw('t.branch_id, a.account_kind, SUM(CASE WHEN e.direction = ? THEN e.amount ELSE -e.amount END) AS balance', [FinancialEntryDirection::Increase->value])
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $balances[$row->branch_id] ??= collect(FinancialAccountKind::cases())
                ->mapWithKeys(fn (FinancialAccountKind $kind): array => [$kind->value => '0.0000'])->all();
            $balances[$row->branch_id][$row->account_kind] = (string) BigDecimal::of((string) $row->balance)->toScale(4);
        }

        return $balances;
    }

    public function recentMovements(FinancialAccount $account, int $limit = 25): Collection
    {
        return FinancialEntry::query()
            ->where('financial_account_id', $account->id)
            ->with('transaction')
            ->latest('id')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }

    /** @return Collection<int, object> Exact, attributable and unreversed Receivable increases. */
    public function payableReceivableEntries(Captain $captain, Branch $branch, Currency $currency): Collection
    {
        $eventTypes = [
            'CAPTAIN_BONUS_DUE', 'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE',
            'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE', 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE',
            'ORDER_CASH_DELIVERY_SHORTFALL_DUE',
            'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
            'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE',
        ];
        if (DB::scalar("SELECT to_regprocedure('corrected_receivable_payouts_active()') IS NOT NULL")
            && DB::scalar('SELECT corrected_receivable_payouts_active()')) {
            $eventTypes = array_merge($eventTypes, [
                'INVOICE_CORRECTION_CASH_SHORTFALL_DUE',
                'INVOICE_CORRECTION_SHAM_PRODUCT_DUE',
                'INVOICE_CORRECTION_SHAM_DELIVERY_DUE',
            ]);
        }
        if (Schema::hasTable('invoice_correction_receivable_dispositions')
            && DB::scalar('SELECT receivable_dispositions_active()')) {
            $eventTypes[] = 'RECEIVABLE_CORRECTED_RESIDUAL_DUE';
        }

        return $this->receivableSourceEntries($captain, $branch, $currency, $eventTypes, v2SharedOnly: false);
    }

    /** Reserved amounts remain economic liabilities until atomic correction completion. */
    public function reservedReceivableEntries(Captain $captain, Branch $branch, Currency $currency): Collection
    {
        if (! Schema::hasTable('invoice_correction_receivable_dispositions')) {
            return collect();
        }
        $sources = $this->receivableSourceEntries($captain, $branch, $currency, [
            'ORDER_CASH_DELIVERY_SHORTFALL_DUE', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
            'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE', 'INVOICE_CORRECTION_CASH_SHORTFALL_DUE',
            'INVOICE_CORRECTION_SHAM_PRODUCT_DUE', 'INVOICE_CORRECTION_SHAM_DELIVERY_DUE',
            'RECEIVABLE_CORRECTED_RESIDUAL_DUE',
        ], true);
        foreach ($sources as $source) {
            $correctionId = DB::table('invoice_correction_receivable_dispositions as d')
                ->join('invoice_financial_corrections as c', 'c.id', '=', 'd.invoice_financial_correction_id')
                ->where('d.original_receivable_financial_entry_id', $source->financial_entry_id)
                ->whereIn('c.state', ['PREPARED', 'READY_TO_FINALIZE'])->value('c.id');
            DB::select('SELECT validate_receivable_disposition_set(?)', [$correctionId]);
        }

        return $sources;
    }

    /** Known immutable sources explain the economic balance while deployment keeps them non-allocatable. */
    public function gatedCorrectedReceivableEntries(Captain $captain, Branch $branch, Currency $currency): Collection
    {
        // Shared liabilities stay visible in balances, but are not an operator payout workflow.
        // Account for them here so they cannot block unrelated, already-supported payouts.
        $shared = $this->receivableSourceEntries($captain, $branch, $currency, [
            'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE', 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE',
            'SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE', 'SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE',
        ], v2SharedOnly: true);
        if (! DB::scalar("SELECT to_regprocedure('corrected_receivable_payouts_active()') IS NOT NULL")
            || DB::scalar('SELECT corrected_receivable_payouts_active()')) {
            return $shared;
        }
        $sources = $this->receivableSourceEntries($captain, $branch, $currency, [
            'INVOICE_CORRECTION_CASH_SHORTFALL_DUE',
            'INVOICE_CORRECTION_SHAM_PRODUCT_DUE',
            'INVOICE_CORRECTION_SHAM_DELIVERY_DUE',
        ]);
        foreach ($sources->unique('financial_transaction_id') as $source) {
            $correction = $source->source_type === 'INVOICE_FINANCIAL_CORRECTION'
                ? DB::table('invoice_financial_corrections')->whereRaw('id::text = ?', [$source->source_id])->first()
                : null;
            $snapshotId = $correction ? DB::table('invoice_correction_financial_snapshots')
                ->where('invoice_financial_correction_id', $correction->id)->value('id') : null;
            if (! $correction || $correction->state !== 'COMPLETED' || ! $snapshotId) {
                throw new FinancialIdempotencyConflictException('FINANCIAL_CORRECTION_REQUIRED: Invalid gated corrected Receivable source.');
            }
            try {
                DB::select('SELECT validate_invoice_correction_snapshot_facts(?)', [$snapshotId]);
                DB::select('SELECT validate_invoice_correction_ledger_transaction(?)', [$source->financial_transaction_id]);
                foreach (['validate_completed_invoice_correction', 'validate_complete_correction_financial_set'] as $validator) {
                    if (DB::scalar('SELECT to_regprocedure(?) IS NOT NULL', [$validator.'(bigint)'])) {
                        DB::select('SELECT '.$validator.'(?)', [$correction->id]);
                    }
                }
            } catch (QueryException $exception) {
                throw new FinancialIdempotencyConflictException('FINANCIAL_CORRECTION_REQUIRED: Invalid gated corrected Receivable source_entry_id='.$source->financial_entry_id, previous: $exception);
            }
        }

        return $sources->concat($shared);
    }

    private function receivableSourceEntries(Captain $captain, Branch $branch, Currency $currency, array $eventTypes, bool $reserved = false, ?bool $v2SharedOnly = null): Collection
    {
        $orderLookup = Schema::hasTable('invoice_financial_corrections')
            ? "CASE WHEN t.source_type = 'INVOICE_COLLECTION' THEN (SELECT ic.order_id FROM invoice_collections ic WHERE ic.id::text = t.source_id) WHEN t.source_type = 'INVOICE_FINANCIAL_CORRECTION' THEN (SELECT c.order_id FROM invoice_financial_corrections c WHERE c.id::text = t.source_id) END"
            : '(SELECT ic.order_id FROM invoice_collections ic WHERE ic.id::text = t.source_id)';
        if (Schema::hasTable('invoice_correction_receivable_dispositions')) {
            $orderLookup = "CASE WHEN t.source_type='INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' THEN (SELECT c.order_id FROM invoice_correction_receivable_dispositions d JOIN invoice_financial_corrections c ON c.id=d.invoice_financial_correction_id WHERE d.id::text=t.source_id) ELSE {$orderLookup} END";
        }

        $query = DB::table('financial_entries as e')
            ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->leftJoin('captain_receivable_payout_allocations as p', 'p.financial_entry_id', '=', 'e.id')
            ->where('o.owner_type', FinancialOwnerType::Captain->value)
            ->where('o.captain_id', $captain->id)
            ->where('a.currency_id', $currency->id)
            ->where('a.account_kind', FinancialAccountKind::CaptainCompanyReceivable->value)
            ->where('t.branch_id', $branch->id)
            ->where('t.transaction_kind', 'EVENT')->where('t.status', 'POSTED')
            ->where('e.direction', FinancialEntryDirection::Increase->value)
            ->whereIn('t.event_type', $eventTypes)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('financial_transactions as r')
                ->whereColumn('r.reversal_of_transaction_id', 't.id'))
            ->groupBy('e.id', 't.id')
            ->selectRaw("e.id AS financial_entry_id, t.id AS financial_transaction_id, t.event_type, t.source_type, t.source_id, t.occurred_at, e.amount, COALESCE(SUM(p.amount), 0) AS paid_amount, e.amount - COALESCE(SUM(p.amount), 0) AS remaining_amount, (SELECT o.order_number FROM orders o WHERE o.id = {$orderLookup} LIMIT 1) AS order_number")
            ->havingRaw('e.amount - COALESCE(SUM(p.amount), 0) > 0')
            ->orderBy('t.occurred_at')->orderBy('e.id');
        if ($v2SharedOnly !== null && Schema::hasTable('order_captain_selections')) {
            $exists = $v2SharedOnly ? 'EXISTS' : 'NOT EXISTS';
            $query->whereRaw("(t.event_type NOT IN ('ORDER_V2_PRODUCT_REIMBURSEMENT_DUE','ORDER_V2_DELIVERY_ENTITLEMENT_DUE') OR {$exists}
                (SELECT 1 FROM invoice_collections vc JOIN order_captain_selections vs ON vs.order_id=vc.order_id
                 WHERE vc.id::text=t.source_id AND t.source_type='INVOICE_COLLECTION' AND vs.contract='SHARED_COMPANY_V1'))");
        }
        if (Schema::hasTable('invoice_correction_receivable_dispositions')) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoice_correction_receivable_dispositions as d')
                ->join('invoice_financial_corrections as c', 'c.id', '=', 'd.invoice_financial_correction_id')
                ->whereColumn('d.original_receivable_financial_entry_id', 'e.id')->where('c.state', 'COMPLETED'));
            $reservation = fn ($q) => $q->selectRaw('1')->from('invoice_correction_receivable_dispositions as d')
                ->join('invoice_financial_corrections as c', 'c.id', '=', 'd.invoice_financial_correction_id')
                ->whereColumn('d.original_receivable_financial_entry_id', 'e.id')->whereIn('c.state', ['PREPARED', 'READY_TO_FINALIZE']);
            $reserved ? $query->whereExists($reservation) : $query->whereNotExists($reservation);
        }

        return $query->get();
    }
}
