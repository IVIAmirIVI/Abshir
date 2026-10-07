<?php

namespace App\Services;

use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
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
                if (filled($data['shop_name'] ?? null) || filled($data['shop_phone'] ?? null) || filled($data['shop_address'] ?? null) || filled($data['origin_area_id'] ?? null)) {
                    throw ValidationException::withMessages(['shop_type' => __('domain.a_registered_shop_cannot_include_one_time_shop_identity_data')]);
                }
                $shop = Shop::query()->with(['area', 'type'])->findOrFail($data['shop_id']);
                abort_unless($actor->canAccessShop($shop), 403);
                if ($shop->area && $shop->area->branch_id !== $order->branch_id) {
                    throw ValidationException::withMessages(['shop_id' => __('domain.the_shop_is_outside_the_order_branch')]);
                }
                if (! $shop->is_active) {
                    throw ValidationException::withMessages(['shop_id' => __('domain.an_inactive_shop_cannot_be_added_to_a_new_order')]);
                }
                if (! $shop->area || ! $shop->area->is_active) {
                    throw ValidationException::withMessages(['shop_id' => __('domain.a_registered_shop_requires_an_active_area_before_it_can_be_added_to_an_order')]);
                }
                if ($order->shops()->where('shop_type', OrderShopType::Registered->value)->where('shop_id', $shop->id)->exists()) {
                    throw ValidationException::withMessages(['shop_id' => __('domain.the_registered_shop_is_already_on_this_order_add_products_to_its_existing_section')]);
                }
                $classification = $shop->type?->business_classification ?? $explicit;
                if (! $classification) {
                    throw ValidationException::withMessages(['business_classification' => __('domain.choose_restaurant_or_shop_because_this_registered_shop_type_is_not_configured')]);
                }
                if ($shop->type?->business_classification && $explicit && $explicit !== $shop->type->business_classification) {
                    throw ValidationException::withMessages(['business_classification' => __('domain.the_selected_classification_conflicts_with_the_registered_shop_type')]);
                }
                $created = $order->shops()->create(['shop_type' => $type, 'business_classification_snapshot' => $classification, 'shop_id' => $shop->id, 'origin_area_id' => $shop->area->id, 'shop_name_snapshot' => $shop->name, 'shop_phone_snapshot' => $shop->phone, 'shop_address_snapshot' => $shop->address, 'area_name_snapshot' => $shop->area->name, 'notes' => $data['notes'] ?? null, 'sort_order' => $data['sort_order'] ?? 0]);
            } else {
                if (! empty($data['shop_id'])) {
                    throw ValidationException::withMessages(['shop_id' => __('domain.a_one_time_shop_cannot_reference_a_registered_shop')]);
                }
                if (! $explicit) {
                    throw ValidationException::withMessages(['business_classification' => __('domain.choose_restaurant_or_shop_for_a_one_time_shop')]);
                }
                $areas = Area::query()->accessibleTo($actor)->where('branch_id', $order->branch_id)->where('is_active', true);
                $origin = filled($data['origin_area_id'] ?? null)
                    ? (clone $areas)->findOrFail($data['origin_area_id'])
                    : ((clone $areas)->limit(2)->get()->count() === 1 ? (clone $areas)->sole() : null);
                if (! $origin) {
                    throw ValidationException::withMessages(['origin_area_id' => __('domain.select_the_one_time_shop_area')]);
                }
                $created = $order->shops()->create(['shop_type' => $type, 'business_classification_snapshot' => $explicit, 'shop_id' => null, 'origin_area_id' => $origin->id, 'shop_name_snapshot' => $data['shop_name'], 'shop_phone_snapshot' => $data['shop_phone'] ?? null, 'shop_address_snapshot' => $data['shop_address'] ?? null, 'area_name_snapshot' => $origin->name, 'notes' => $data['notes'] ?? null, 'sort_order' => $data['sort_order'] ?? 0]);
            }

            $this->classifications->recalculateLocked($order);

            return $created;
        });
    }

    public function remove(User $actor, Order $order, OrderShop $orderShop): void
    {
        Gate::forUser($actor)->authorize('orders.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($orderShop->order_id !== $order->id, 404);

        DB::transaction(function () use ($order, $orderShop): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);
            $orderShop = OrderShop::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($orderShop->id);
            if ($reason = $this->removalBlockReason($order, $orderShop)) {
                throw ValidationException::withMessages(['shop' => $reason]);
            }
            $orderShop->delete();
            $this->classifications->recalculateLocked($order);
        });
    }

    public function removalBlockReason(Order $order, OrderShop $orderShop): ?string
    {
        if ($orderShop->items()->exists()) {
            return __('domain.remove_the_shop_items_before_removing_the_shop');
        }
        if ($orderShop->purchaseConfirmation()->exists() || $orderShop->receipts()->exists() || $orderShop->shopReturn()->exists()
            || $orderShop->contacted_at || $orderShop->confirmed_at || $orderShop->ready_at || $orderShop->picked_up_at) {
            return __('domain.a_shop_with_operational_or_purchase_history_cannot_be_removed');
        }
        if ($order->invoices()->exists()) {
            return __('domain.an_order_shop_cannot_be_removed_after_invoice_history_exists');
        }

        return null;
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
                throw ValidationException::withMessages(['business_classification' => __('domain.the_order_shop_classification_snapshot_is_immutable')]);
            }
            $orderShop->update(['business_classification_snapshot' => ShopBusinessClassification::from($classification)]);
            $this->classifications->recalculateLocked($order);

            return $orderShop->fresh();
        });
    }
}
