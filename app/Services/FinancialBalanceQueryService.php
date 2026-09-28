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
    public function captainBalancesByBranch(Captain $captain, Currency $currency): array
    {
        $rows = DB::table('financial_entries as e')
            ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->where('o.owner_type', FinancialOwnerType::Captain->value)
            ->where('o.captain_id', $captain->id)
            ->where('a.currency_id', $currency->id)
            ->whereNotNull('t.branch_id')
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
}
