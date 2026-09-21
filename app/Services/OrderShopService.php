<?php

namespace App\Services;

use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderShopService
{
    public function __construct(private readonly OrderPricingClassificationService $classifications, private readonly OrderMutationGuard $mutationGuard) {}

    public function add(User $actor, Order $order, array $data): OrderShop
    {
        Gate::forUser($actor)->authorize('orders.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);

        return DB::transaction(function () use ($actor, $order, $data): OrderShop {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);

            $type = OrderShopType::from($data['shop_type']);
            $explicit = isset($data['business_classification']) ? ShopBusinessClassification::from($data['business_classification']) : null;
            if ($type === OrderShopType::Registered) {
                $shop = Shop::query()->with(['area', 'type'])->findOrFail($data['shop_id']);
                abort_unless($actor->canAccessShop($shop), 403);
                if ($shop->area && $shop->area->branch_id !== $order->branch_id) {
                    throw ValidationException::withMessages(['shop_id' => 'The Shop is outside the Order Branch.']);
                }
                if (! $shop->is_active) {
                    throw ValidationException::withMessages(['shop_id' => 'An inactive Shop cannot be added to a new Order.']);
                }
                $classification = $shop->type?->business_classification ?? $explicit;
                if (! $classification) {
                    throw ValidationException::withMessages(['business_classification' => 'Choose Restaurant or Shop because this registered Shop Type is not configured.']);
                }
                if ($shop->type?->business_classification && $explicit && $explicit !== $shop->type->business_classification) {
                    throw ValidationException::withMessages(['business_classification' => 'The selected classification conflicts with the registered Shop Type.']);
                }
                $created = $order->shops()->create(['shop_type' => $type, 'business_classification_snapshot' => $classification, 'shop_id' => $shop->id, 'shop_name_snapshot' => $shop->name, 'shop_phone_snapshot' => $shop->phone, 'shop_address_snapshot' => $shop->address, 'area_name_snapshot' => $shop->area?->name, 'notes' => $data['notes'] ?? null, 'sort_order' => $data['sort_order'] ?? 0]);
            } else {
                if (! empty($data['shop_id'])) {
                    throw ValidationException::withMessages(['shop_id' => 'A one-time Shop cannot reference a registered Shop.']);
                }
                if (! $explicit) {
                    throw ValidationException::withMessages(['business_classification' => 'Choose Restaurant or Shop for a one-time Shop.']);
                }
                $created = $order->shops()->create(['shop_type' => $type, 'business_classification_snapshot' => $explicit, 'shop_id' => null, 'shop_name_snapshot' => $data['shop_name'], 'shop_phone_snapshot' => $data['shop_phone'] ?? null, 'shop_address_snapshot' => $data['shop_address'] ?? null, 'notes' => $data['notes'] ?? null, 'sort_order' => $data['sort_order'] ?? 0]);
            }

            $this->classifications->recalculateLocked($order);

            return $created;
        });
    }

    public function classifyLegacy(User $actor, Order $order, OrderShop $orderShop, string $classification): OrderShop
    {
        Gate::forUser($actor)->authorize('orders.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($orderShop->order_id !== $order->id, 404);

        return DB::transaction(function () use ($order, $orderShop, $classification): OrderShop {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);
            $orderShop = OrderShop::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($orderShop->id);
            if ($orderShop->business_classification_snapshot) {
                throw ValidationException::withMessages(['business_classification' => 'The Order Shop classification snapshot is immutable.']);
            }
            $orderShop->update(['business_classification_snapshot' => ShopBusinessClassification::from($classification)]);
            $this->classifications->recalculateLocked($order);

            return $orderShop->fresh();
        });
    }
}
