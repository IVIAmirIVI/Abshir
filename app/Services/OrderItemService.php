<?php

namespace App\Services;

use App\Enums\ActualPriceSource;
use App\Enums\OrderEstimateMethod;
use App\Enums\OrderItemChangeType;
use App\Enums\OrderItemType;
use App\Enums\OrderShopType;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderItemService
{
    public function __construct(private readonly ProductPriceService $prices, private readonly OrderPricingService $pricingStatus, private readonly OrderMutationGuard $mutationGuard) {}

    public function add(User $actor, Order $order, OrderShop $orderShop, array $data): OrderItem
    {
        Gate::forUser($actor)->authorize('orders.update');
        $this->ownership($actor, $order, $orderShop);
        $type = OrderItemType::from($data['item_type']);
        $unit = Unit::query()->findOrFail($data['unit_id']);
        if (! $unit->is_active) {
            throw ValidationException::withMessages(['unit_id' => 'An inactive Unit cannot be used for a new item.']);
        } $this->validateQuantity($data['quantity'], $unit);
        $product = null;
        if ($type === OrderItemType::Catalog) {
            $product = Product::query()->findOrFail($data['product_id']);
            if (! $product->is_active) {
                throw ValidationException::withMessages(['product_id' => 'An inactive Product cannot be used for a new item.']);
            }
        } elseif (! empty($data['product_id'])) {
            throw ValidationException::withMessages(['product_id' => 'A one-time item cannot reference a Product.']);
        }

        return DB::transaction(function () use ($actor, $order, $orderShop, $data, $type, $unit, $product): OrderItem {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);
            $orderShop = OrderShop::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($orderShop->id);
            $estimate = $this->estimate($actor, $orderShop, $product, $unit, $data);
            $item = $orderShop->items()->create(['item_type' => $type, 'product_id' => $product?->id, 'product_name_snapshot' => $product?->name ?? $data['item_name'], 'unit_id' => $unit->id, 'unit_name_snapshot' => $unit->name, 'unit_symbol_snapshot' => $unit->symbol, 'requested_quantity' => $data['quantity'], 'current_quantity' => $data['quantity'], 'notes' => $data['notes'] ?? null, ...$estimate]);
            $this->pricingStatus->recalculate($order);

            return $item;
        });
    }

    public function confirmActual(User $actor, Order $order, OrderShop $shop, OrderItem $item, array $data): void
    {
        Gate::forUser($actor)->authorize('orders.pricing.update');
        $this->ownership($actor, $order, $shop, $item);
        $currency = Currency::query()->findOrFail($data['actual_currency_id']);
        if (! $currency->is_active) {
            throw ValidationException::withMessages(['actual_currency_id' => 'An inactive Currency cannot be used for a new actual price.']);
        }
        DB::transaction(function () use ($actor, $order, $item, $data): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->update(['actual_unit_price' => $data['actual_unit_price'], 'actual_currency_id' => $data['actual_currency_id'], 'actual_price_source' => ActualPriceSource::from($data['actual_price_source']), 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $actor->id]);
            $this->pricingStatus->recalculate($order);
        });
    }

    public function change(User $actor, Order $order, OrderShop $shop, OrderItem $item, array $data): void
    {
        Gate::forUser($actor)->authorize('orders.items.change');
        $this->ownership($actor, $order, $shop, $item);
        $type = OrderItemChangeType::from($data['change_type']);
        DB::transaction(function () use ($actor, $order, $item, $data, $type): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $previous = $item->only(['item_type', 'product_id', 'product_name_snapshot', 'unit_id', 'unit_name_snapshot', 'unit_symbol_snapshot', 'current_quantity']);
            $updates = [];
            if ($type === OrderItemChangeType::Quantity) {
                $unit = $item->unit;
                $this->validateQuantity($data['quantity'], $unit);
                $updates['current_quantity'] = $data['quantity'];
            } else {
                $newType = OrderItemType::from($data['item_type']);
                $unit = Unit::query()->findOrFail($data['unit_id']);
                if (! $unit->is_active) {
                    throw ValidationException::withMessages(['unit_id' => 'Replacement Unit must be active.']);
                } $this->validateQuantity($data['quantity'], $unit);
                $product = $newType === OrderItemType::Catalog ? Product::query()->findOrFail($data['product_id']) : null;
                if ($product && ! $product->is_active) {
                    throw ValidationException::withMessages(['product_id' => 'Replacement Product must be active.']);
                } if ($newType === OrderItemType::OneTime && ! empty($data['product_id'])) {
                    throw ValidationException::withMessages(['product_id' => 'One-time replacement cannot reference a Product.']);
                } $updates = ['item_type' => $newType, 'product_id' => $product?->id, 'product_name_snapshot' => $product?->name ?? $data['item_name'], 'unit_id' => $unit->id, 'unit_name_snapshot' => $unit->name, 'unit_symbol_snapshot' => $unit->symbol, 'current_quantity' => $data['quantity'], 'estimated_unit_price' => null, 'estimated_price_method' => null, 'estimated_source_shop_id' => null, 'estimated_source_price_observation_id' => null, 'estimated_source_observed_at' => null, 'estimation_window_days' => null, 'estimated_currency_id' => null, 'actual_unit_price' => null, 'actual_price_source' => null, 'actual_currency_id' => null, 'actual_price_confirmed_at' => null, 'actual_price_confirmed_by' => null];
            }
            $item->update($updates);
            $item->changes()->create(['change_type' => $type, 'previous_values' => $previous, 'new_values' => $item->fresh()->only(array_keys($updates)), 'changed_by' => $actor->id, 'changed_at' => now(), 'reason' => $data['reason']]);
            $this->pricingStatus->recalculate($order);
        });
    }

    private function estimate(User $actor, OrderShop $shop, ?Product $product, Unit $unit, array $data): array
    {
        if (! $product || empty($data['estimated_currency_id'])) {
            return $this->manualEstimate($data);
        }
        $currency = Currency::query()->findOrFail($data['estimated_currency_id']);
        if (! $currency->is_active) {
            throw ValidationException::withMessages(['estimated_currency_id' => 'An inactive Currency cannot be used for a new estimate.']);
        }
        $observation = null;
        if ($shop->shop_type === OrderShopType::Registered) {
            $observation = $this->prices->getLatestShopPrice($product, $shop->shop, $unit, $currency);
            if ($observation) {
                return ['estimated_unit_price' => $observation->price, 'estimated_price_method' => OrderEstimateMethod::KnownShopLatest, 'estimated_source_shop_id' => $observation->shop_id, 'estimated_source_price_observation_id' => $observation->id, 'estimated_source_observed_at' => $observation->observed_at, 'estimated_currency_id' => $currency->id];
            }
        } else {
            $result = $this->prices->estimateUnknownShopPrice($product, $unit, $currency, now(), $actor);
            if ($result->available()) {
                return ['estimated_unit_price' => $result->price, 'estimated_price_method' => OrderEstimateMethod::LatestShopLowest, 'estimated_source_shop_id' => $result->sourceShopId, 'estimated_source_price_observation_id' => $result->sourceObservationId, 'estimated_source_observed_at' => $result->sourceObservedAt, 'estimation_window_days' => $result->estimationWindowDays, 'estimated_currency_id' => $currency->id];
            }
        }

        return $this->manualEstimate($data);
    }

    private function manualEstimate(array $data): array
    {
        if (empty($data['manual_estimated_unit_price'])) {
            return [];
        } if (empty($data['estimated_currency_id'])) {
            throw ValidationException::withMessages(['estimated_currency_id' => 'Currency is required for a manual estimate.']);
        }

        $currency = Currency::query()->findOrFail($data['estimated_currency_id']);
        if (! $currency->is_active) {
            throw ValidationException::withMessages(['estimated_currency_id' => 'An inactive Currency cannot be used for a new estimate.']);
        }

        return ['estimated_unit_price' => $data['manual_estimated_unit_price'], 'estimated_price_method' => OrderEstimateMethod::Manual, 'estimated_currency_id' => $data['estimated_currency_id']];
    }

    private function validateQuantity(string $quantity, Unit $unit): void
    {
        if (BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        } if (! $unit->allows_decimal && ! preg_match('/^\d+(?:\.0{1,4})?$/', $quantity)) {
            throw ValidationException::withMessages(['quantity' => 'This Unit requires a whole-number quantity.']);
        }
    }

    private function ownership(User $actor, Order $order, OrderShop $shop, ?OrderItem $item = null): void
    {
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($shop->order_id !== $order->id || ($item && $item->order_shop_id !== $shop->id), 404);
    }
}
