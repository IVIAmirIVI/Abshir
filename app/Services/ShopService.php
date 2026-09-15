<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShopService
{
    public function save(User $actor, Shop $shop, array $data): Shop
    {
        Gate::forUser($actor)->authorize($shop->exists ? 'shops.update' : 'shops.create');

        return DB::transaction(function () use ($actor, $shop, $data): Shop {
            if ($shop->exists) {
                $shop = Shop::query()->lockForUpdate()->findOrFail($shop->id);
                abort_unless($actor->canAccessShop($shop), 403);
            }
            $area = isset($data['area_id']) ? Area::query()->findOrFail($data['area_id']) : null;
            if (! $actor->isSuperAdmin() && ! $area) {
                throw ValidationException::withMessages(['area_id' => 'A branch-scoped user must select an accessible Area.']);
            }
            if ($area) {
                abort_unless($actor->canAccessArea($area), 403);
                if (! $area->is_active && $shop->area_id !== $area->id) {
                    throw ValidationException::withMessages(['area_id' => 'An inactive Area cannot be assigned to a Shop.']);
                }
            }
            if (isset($data['shop_type_id'])) {
                $type = ShopType::query()->findOrFail($data['shop_type_id']);
                if (! $type->is_active && $shop->shop_type_id !== $type->id) {
                    throw ValidationException::withMessages(['shop_type_id' => 'An inactive Shop Type cannot be assigned to a Shop.']);
                }
            }

            $shop->fill($data)->save();

            return $shop;
        });
    }

    public function setActive(User $actor, Shop $shop, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'shops.activate' : 'shops.deactivate');
        abort_unless($actor->canAccessShop($shop), 403);
        $shop->is_active = $active;
        $shop->save();
    }
}
