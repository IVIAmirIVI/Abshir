<?php

namespace App\Services;

use App\Http\Requests\ProductRequest;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InlineOrderProductService
{
    public function create(User $actor, Order $order, OrderShop $orderShop, array $data): Product
    {
        app(OrderService::class)->assertAccess($actor, $order);
        Gate::forUser($actor)->authorize('orders.update');
        Gate::forUser($actor)->authorize('products.create');
        abort_if($orderShop->order_id !== $order->id, 404);

        return DB::transaction(function () use ($actor, $order, $orderShop, $data): Product {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            app(OrderMutationGuard::class)->assertMutableLocked($order);
            $orderShop = $order->shops()->findOrFail($orderShop->id);

            return $this->createCatalogProduct($actor, $orderShop->shop, $data);
        });
    }

    /** The wizard creates catalog records before an Order exists. */
    public function createForDraft(User $actor, ?Shop $shop, array $data): Product
    {
        Gate::forUser($actor)->authorize('orders.create');

        return $this->createCatalogProduct($actor, $shop, $data);
    }

    private function createCatalogProduct(User $actor, ?Shop $shop, array $data): Product
    {
        Gate::forUser($actor)->authorize('products.create');
        if ($shop) {
            abort_unless($actor->canAccessShop($shop), 403);
        }
        $data['name'] = trim($data['name']);
        $data = Validator::make($data, array_intersect_key((new ProductRequest)->rules(), array_flip(['name', 'sku', 'default_unit_id', 'category_id'])))->validate();

        return DB::transaction(function () use ($actor, $shop, $data): Product {
            $identity = mb_strtolower($data['name']);
            // Serialize this inline check/create only; catalog Product names remain non-unique.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['abshir:inline-product:'.$identity]);
            if (Product::query()->whereRaw('LOWER(TRIM(name)) = ?', [$identity])->exists()) {
                throw ValidationException::withMessages(['name' => __('orders.ui_product_already_exists')]);
            }
            $product = app(ProductService::class)->save($actor, new Product, $data);
            if ($shop && Gate::forUser($actor)->allows('shop_products.create')) {
                app(ShopProductService::class)->save($actor, $shop, new ShopProduct, [
                    'product_id' => $product->id, 'unit_id' => $product->default_unit_id,
                    'is_available' => true, 'is_featured' => false,
                ]);
            }

            return $product;
        });
    }
}
