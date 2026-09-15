<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CurrencyService
{
    public function save(User $actor, Currency $currency, array $data): Currency
    {
        Gate::forUser($actor)->authorize($currency->exists ? 'currencies.update' : 'currencies.create');
        $data['code'] = strtoupper($data['code']);
        $currency->fill($data)->save();

        return $currency;
    }

    public function setActive(User $actor, Currency $currency, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'currencies.activate' : 'currencies.deactivate');
        $currency->is_active = $active;
        $currency->save();
    }
}
