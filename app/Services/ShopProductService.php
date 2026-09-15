<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopProduct;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShopProductService
{
    public function save(User $actor, Shop $shop, ShopProduct $listing, array $data): ShopProduct
    {
        Gate::forUser($actor)->authorize($listing->exists ? 'shop_products.update' : 'shop_products.create');
        abort_unless($actor->canAccessShop($shop), 403);
        abort_if($listing->exists && $listing->shop_id !== $shop->id, 404);

        return DB::transaction(function () use ($shop, $listing, $data): ShopProduct {
            Shop::query()->lockForUpdate()->findOrFail($shop->id);
            $product = Product::query()->findOrFail($data['product_id']);
            $unit = Unit::query()->findOrFail($data['unit_id']);
            $currency = isset($data['currency_id']) ? Currency::query()->findOrFail($data['currency_id']) : null;
            foreach ([['product_id', $product], ['unit_id', $unit], ['currency_id', $currency]] as [$field, $record]) {
                if ($record && ! $record->is_active && (! $listing->exists || $listing->{$field} !== $record->id)) {
                    throw ValidationException::withMessages([$field => "An inactive {$record->getMorphClass()} cannot be selected."]);
                }
            }
            $duplicate = ShopProduct::query()->where('shop_id', $shop->id)
                ->where('product_id', $product->id)->where('unit_id', $unit->id)
                ->when($listing->exists, fn ($query) => $query->whereKeyNot($listing->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['product_id' => 'This Shop already has a listing for the Product and Unit.']);
            }

            $listing->fill($data);
            $listing->shop_id = $shop->id;
            $listing->save();

            return $listing;
        });
    }

    public function setActive(User $actor, Shop $shop, ShopProduct $listing, bool $active): void
    {
        Gate::forUser($actor)->authorize('shop_products.update');
        abort_unless($actor->canAccessShop($shop), 403);
        abort_if($listing->shop_id !== $shop->id, 404);
        $listing->is_active = $active;
        $listing->save();
    }
}
