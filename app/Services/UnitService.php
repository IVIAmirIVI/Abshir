<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UnitService
{
    public function save(User $actor, Unit $unit, array $data): Unit
    {
        Gate::forUser($actor)->authorize($unit->exists ? 'units.update' : 'units.create');
        $data['code'] = strtoupper($data['code']);
        $unit->fill($data)->save();

        return $unit;
    }

    public function setActive(User $actor, Unit $unit, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'units.activate' : 'units.deactivate');
        $unit->is_active = $active;
        $unit->save();
    }
}
