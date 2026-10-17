<?php

namespace App\Services;

use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\User;
use App\Rules\MobilePhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
            Validator::make($data, ['phone' => ['nullable', 'string', new MobilePhone]])->validate();
            $area = isset($data['area_id']) ? Area::query()->findOrFail($data['area_id']) : null;
            if (! $actor->isSuperAdmin() && ! $area) {
                throw ValidationException::withMessages(['area_id' => __('domain.a_branch_scoped_user_must_select_an_accessible_area')]);
            }
            if ($area) {
                abort_unless($actor->canAccessArea($area), 403);
                if (! $area->is_active && $shop->area_id !== $area->id) {
                    throw ValidationException::withMessages(['area_id' => __('domain.an_inactive_area_cannot_be_assigned_to_a_shop')]);
                }
            }
            if (isset($data['shop_type_id'])) {
                $type = ShopType::query()->findOrFail($data['shop_type_id']);
                if (! $type->is_active && $shop->shop_type_id !== $type->id) {
                    throw ValidationException::withMessages(['shop_type_id' => __('domain.an_inactive_shop_type_cannot_be_assigned_to_a_shop')]);
                }
            }

            $classification = Validator::make([
                'business_classification' => $data['business_classification'] ?? $shop->business_classification?->value,
            ], ['business_classification' => ['required', Rule::enum(ShopBusinessClassification::class)]])->validate();
            $shop->fill(array_merge($data, $classification))->save();

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
