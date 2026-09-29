<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialOwnerType;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialEntry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        return DB::table('financial_entries as e')
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
            ->whereIn('t.event_type', [
                'ORDER_CASH_DELIVERY_SHORTFALL_DUE',
                'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
                'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE',
            ])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('financial_transactions as r')
                ->whereColumn('r.reversal_of_transaction_id', 't.id'))
            ->groupBy('e.id', 't.id')
            ->selectRaw("e.id AS financial_entry_id, t.id AS financial_transaction_id, t.event_type, t.source_type, t.source_id, t.occurred_at, e.amount, COALESCE(SUM(p.amount), 0) AS paid_amount, e.amount - COALESCE(SUM(p.amount), 0) AS remaining_amount, (SELECT o.order_number FROM invoice_collections ic JOIN orders o ON o.id = ic.order_id WHERE t.source_type = 'INVOICE_COLLECTION' AND ic.id::text = t.source_id LIMIT 1) AS order_number")
            ->havingRaw('e.amount - COALESCE(SUM(p.amount), 0) > 0')
            ->orderBy('t.occurred_at')->orderBy('e.id')->get();
    }
}
