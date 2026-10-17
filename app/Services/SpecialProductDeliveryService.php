<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderDeliveryPricing;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDeliveryPolicy;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Resolves attributable delivery sources; posting remains in the existing accounting services. */
class SpecialProductDeliveryService
{
    public function assertCurrentPricing(Order $order, OrderDeliveryPricing $pricing): void
    {
        if (! Schema::hasTable('product_delivery_policies')) {
            return;
        }
        $rows = $pricing->allocations()->get();
        if ($this->orderSources($order) === [] && $rows->every(fn ($row) => $row->special_delivery_components === null)) {
            return;
        }
        try {
            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    if ($row->special_delivery_components === null) {
                        throw ValidationException::withMessages(['invoice' => __('special.stale')]);
                    }
                    DB::select('SELECT assert_special_delivery_allocation(to_jsonb(a)) FROM order_delivery_allocations a WHERE id=?', [$row->id]);
                }
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['invoice' => __('special.stale')]);
        }
    }

    public function assign(User $actor, Order $order, array $assignments): void
    {
        Gate::forUser($actor)->authorize('orders.update');
        Gate::forUser($actor)->authorize('delivery_pricing.apply');
        app(OrderService::class)->assertAccess($actor, $order);
        DB::transaction(function () use ($order, $assignments): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            app(OrderMutationGuard::class)->assertMutableLocked($order);
            $sources = $this->orderSources($order);
            if (array_diff(array_keys($assignments), array_column($sources, 'key'))) {
                throw ValidationException::withMessages(['special_delivery' => __('special.assign_portions')]);
            }
            foreach ($sources as $source) {
                OrderItem::findOrFail($source['item_id'])->forceFill(['special_delivery_assignments' => $assignments[$source['key']] ?? []])->save();
            }
            $this->orderQuote($order, $order->branch->defaultCurrency,
                $order->captainAssignments()->whereNull('removed_at')->pluck('captain_id')->all());
        });
    }

    public function policy(Product $product, Currency $currency): ?ProductDeliveryPolicy
    {
        if (! $product->is_special) {
            return null;
        }
        $policy = $product->deliveryPolicy;
        if (! $product->is_active || ! $policy || $policy->currency_id !== $currency->id) {
            throw ValidationException::withMessages(['special_delivery' => __('special.currency_unavailable')]);
        }

        return $policy;
    }

    public function captureItem(Order $order, ?Product $product, ?int $unitId = null): array
    {
        if (! $product || ! Schema::hasTable('product_delivery_policies')) {
            return [];
        }
        $product = Product::query()->sharedLock()->findOrFail($product->id);
        $policy = $this->policy($product, $order->branch->defaultCurrency);
        if ($policy !== null && $unitId !== $policy->unit_id) {
            throw ValidationException::withMessages(['special_delivery' => __('special.whole_portions')]);
        }

        return $policy ? ['special_delivery_policy_id' => $policy->id] : [];
    }

    public function orderSources(Order $order): array
    {
        if (! Schema::hasTable('product_delivery_policies')) {
            return [];
        }

        return OrderItem::query()->whereHas('orderShop', fn ($q) => $q->where('order_id', $order->id))
            ->whereNotNull('special_delivery_policy_id')->orderBy('id')->get()->map(fn ($item) => [
                'key' => (string) $item->id, 'shop_key' => $item->order_shop_id, 'item_id' => $item->id, 'product_id' => $item->product_id,
                'label' => $item->product_name_snapshot, 'quantity' => $item->current_quantity,
                'policy' => ProductDeliveryPolicy::findOrFail($item->special_delivery_policy_id),
                'assignments' => $item->special_delivery_assignments ?? [],
            ])->all();
    }

    public function draftSources(array $groups, Currency $currency, array $assignments = []): array
    {
        if (! Schema::hasTable('product_delivery_policies')) {
            return [];
        }
        $products = Product::with('deliveryPolicy')->whereIn('id', collect($groups)->pluck('items')->flatten(1)->pluck('product_id')->filter())->get()->keyBy('id');
        $sources = [];
        foreach ($groups as $key => $group) {
            foreach ($group['items'] as $index => $item) {
                $product = $products->get($item['product_id'] ?? null);
                if ($product && ($policy = $this->policy($product, $currency))) {
                    if ((int) $item['unit_id'] !== $policy->unit_id) {
                        throw ValidationException::withMessages(['special_delivery' => __('special.whole_portions')]);
                    }
                    $sourceKey = $key.'_'.$index;
                    $sources[] = ['key' => $sourceKey, 'shop_key' => $key, 'item_id' => null, 'product_id' => $product->id, 'label' => $product->name,
                        'quantity' => $item['quantity'], 'policy' => $policy, 'assignments' => $assignments[$sourceKey] ?? []];
                }
            }
        }

        return $sources;
    }

    public function orderQuote(Order $order, Currency $currency, array $captainIds, bool $strict = true): array
    {
        $sources = $this->orderSources($order);
        $approvalIds = $sources !== [] && $order->shops()->count() > 1 ? app(SpecialAddonApprovalService::class)->ids($order) : [];
        $quote = $this->quote($sources, $currency, $captainIds, $strict, max(1, count(array_unique(array_column($sources, 'shop_key')))));

        return $quote === [] ? [] : $quote + ['special_addon_ids' => $approvalIds, 'special_anchor_shop' => $sources[0]['shop_key']];
    }

    public function quote(array $sources, Currency $currency, array $captainIds, bool $strict = true, int $shopCount = 1): array
    {
        if ($sources === []) {
            return [];
        }
        if ($shopCount > 1) {
            throw ValidationException::withMessages(['special_delivery' => __('special.multi_shop_blocked')]);
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($captainIds))));
        $parts = array_fill_keys($ids ?: [0], []);
        $review = [];
        $incomplete = $ids === [];
        foreach ($sources as $source) {
            $policy = $source['policy'];
            if ($policy->currency_id !== $currency->id || ! preg_match('/^[1-9][0-9]*(?:\.0{1,4})?$/D', (string) $source['quantity'])) {
                throw ValidationException::withMessages(['special_delivery' => __('special.whole_portions')]);
            }
            $quantity = BigDecimal::of($source['quantity'])->toScale(0)->toInt();
            $assigned = $source['assignments'];
            if (! is_array($assigned)) {
                throw ValidationException::withMessages(['special_delivery' => __('special.assign_portions')]);
            }
            if (count($ids) <= 1 && $assigned === []) {
                $assigned = [($ids[0] ?? 0) => (string) $quantity];
            }
            $total = 0;
            $normalized = [];
            foreach ($assigned as $captainId => $units) {
                if (! preg_match('/^[1-9][0-9]*$/D', (string) $captainId) && (string) $captainId !== '0'
                    || ! is_scalar($units) || ! preg_match('/^[0-9]{1,6}$/D', (string) $units) || ! array_key_exists((int) $captainId, $parts)) {
                    $incomplete = true;

                    continue;
                }
                $count = (int) $units;
                $total += $count;
                if ($count === 0) {
                    continue;
                }
                $normalized[(int) $captainId] = (string) $count;
                $fee = BigDecimal::of($policy->delivery_fee)->multipliedBy($count);
                $custom = $policy->allocation_mode === 'FIXED' ? BigDecimal::of($policy->captain_fixed)->multipliedBy($count)
                    : ($policy->allocation_mode === 'PERCENTAGE' ? $fee->multipliedBy($policy->captain_percentage)->dividedBy(100, $currency->decimal_places, RoundingMode::HalfUp) : BigDecimal::zero());
                $parts[(int) $captainId][] = [
                    'item_id' => $source['item_id'], 'product_id' => $source['product_id'], 'product_name' => $source['label'], 'policy_id' => $policy->id,
                    'units' => (string) $count, 'base_fee' => (string) $fee->toScale(4),
                    'custom_captain' => (string) $custom->toScale(4), 'mode' => $policy->allocation_mode,
                ];
            }
            ksort($normalized);
            $review[] = ['product_id' => $source['product_id'], 'policy_id' => $policy->id, 'quantity' => (string) $quantity, 'assignments' => $normalized];
            $incomplete = $incomplete || $total !== $quantity;
        }
        $fees = array_map(fn ($rows) => (string) array_reduce($rows, fn ($sum, $row) => BigDecimal::of($sum)->plus($row['base_fee']), BigDecimal::zero())->toScale(4), $parts);
        $incomplete = $incomplete || count(array_unique($fees)) !== 1 || in_array('0.0000', $fees, true);
        if ($strict && $incomplete) {
            throw ValidationException::withMessages(['special_delivery' => __('special.assign_portions')]);
        }
        if ($incomplete) {
            $fallback = array_reduce($sources, fn ($sum, $source) => BigDecimal::of($sum)->plus(BigDecimal::of($source['policy']->delivery_fee)->multipliedBy($source['quantity'])), BigDecimal::zero());
        }

        return ['special_sources' => $sources, 'special_parts' => $parts, 'special_review' => $review,
            'special_incomplete' => $incomplete, 'suggested_unit_fee' => $incomplete ? (string) $fallback->toScale(4) : reset($fees)];
    }
}
