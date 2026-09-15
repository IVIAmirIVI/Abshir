<?php

namespace App\Services;

use App\Models\ShopType;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ShopTypeService
{
    public function save(User $actor, ShopType $type, array $data): ShopType
    {
        Gate::forUser($actor)->authorize($type->exists ? 'shop_types.update' : 'shop_types.create');
        $type->fill($data)->save();

        return $type;
    }

    public function setActive(User $actor, ShopType $type, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'shop_types.activate' : 'shop_types.deactivate');
        $type->is_active = $active;
        $type->save();
    }
}
