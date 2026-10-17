<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function save(User $actor, Product $product, array $data): Product
    {
        Gate::forUser($actor)->authorize($product->exists ? 'products.update' : 'products.create');

        if (array_key_exists('is_special', $data) || array_key_exists('special_policy', $data)) {
            abort_unless($actor->isSuperAdmin(), 403);
        }

        return DB::transaction(function () use ($actor, $product, $data): Product {
            if ($product->exists) {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            }
            $unit = Unit::query()->findOrFail($data['default_unit_id']);
            if (! $unit->is_active && $product->default_unit_id !== $unit->id) {
                throw ValidationException::withMessages(['default_unit_id' => __('domain.an_inactive_unit_cannot_be_selected_as_a_new_default')]);
            }
            if (isset($data['category_id'])) {
                $category = Category::query()->findOrFail($data['category_id']);
                if (! $category->is_active && $product->category_id !== $category->id) {
                    throw ValidationException::withMessages(['category_id' => __('domain.an_inactive_category_cannot_be_assigned_to_a_product')]);
                }
            }

            $policy = $data['special_policy'] ?? null;
            unset($data['special_policy']);
            $product->fill($data)->save();
            if ($product->is_special) {
                if ($policy !== null) {
                    app(ProductDeliveryPolicyService::class)->saveLocked($actor, $product, $policy);
                } elseif (! $product->deliveryPolicies()->exists() || (int) $product->deliveryPolicy->unit_id !== (int) $product->default_unit_id) {
                    throw ValidationException::withMessages(['special_policy' => __('special.invalid_policy')]);
                }
            }

            return $product;
        });
    }

    public function setActive(User $actor, Product $product, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'products.activate' : 'products.deactivate');
        $product->is_active = $active;
        $product->save();
    }
}
