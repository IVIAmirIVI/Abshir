<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialOwnerType;
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
