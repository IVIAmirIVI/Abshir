<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CurrencyService
{
    public function save(User $actor, Currency $currency, array $data): Currency
    {
        Gate::forUser($actor)->authorize($currency->exists ? 'currencies.update' : 'currencies.create');
        $data['code'] = strtoupper($data['code']);

        return DB::transaction(function () use ($currency, $data): Currency {
            if ($currency->exists) {
                $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
                if (array_key_exists('decimal_places', $data)
                    && (int) $data['decimal_places'] !== (int) $currency->decimal_places
                    && (FinancialTransaction::query()->where('currency_id', $currency->id)->exists()
                        || (Schema::hasTable('order_shop_purchase_confirmations')
                            && DB::table('order_shop_purchase_confirmations')->where('currency_id', $currency->id)->exists()))) {
                    throw ValidationException::withMessages([
                        'decimal_places' => __('domain.currency_decimal_precision_cannot_change_after_financial_ledger_or_verified_shop_purchase_use'),
                    ]);
                }
            }
            $currency->fill($data)->save();

            return $currency;
        }, 3);
    }

    public function setActive(User $actor, Currency $currency, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'currencies.activate' : 'currencies.deactivate');
        $currency->is_active = $active;
        $currency->save();
    }
}
