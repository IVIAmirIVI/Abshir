<?php

namespace Tests\Support;

use App\Enums\DeliveryPricingStatus;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\DeliveryPricingRule;
use App\Models\Order;
use App\Models\OrderDeliveryPricing;
use App\Models\OrderShop;
use App\Models\User;
use App\Services\DeliveryAllocationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Source fixtures for migration staging, not replacement business operations. */
trait HistoricalOrderFixtures
{
    protected function historicalShop(Order $order, string $name): OrderShop
    {
        if (Schema::hasColumn('order_shops', 'origin_area_id')) {
            throw new LogicException('Historical Shop fixtures require the pre-origin schema.');
        }

        return $order->shops()->create([
            'shop_type' => OrderShopType::OneTime,
            'business_classification_snapshot' => ShopBusinessClassification::Shop,
            'shop_name_snapshot' => $name,
            'sort_order' => $order->shops()->count(),
        ]);
    }

    protected function historicalDeliveryPricing(User $actor, Order $order, DeliveryPricingRule $rule, string $share): OrderDeliveryPricing
    {
        if (Schema::hasColumn('order_delivery_pricings', 'route_count_snapshot')) {
            throw new LogicException('Historical pricing fixtures require the pre-route schema.');
        }
        $assignments = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->get();
        $gross = BigDecimal::of($rule->delivery_unit_fee)->multipliedBy($assignments->count());
        $pricing = $order->deliveryPricings()->create([
            'version' => 1, 'delivery_pricing_rule_id' => $rule->id,
            'from_area_id' => $rule->from_area_id, 'to_area_id' => $rule->to_area_id,
            'currency_id' => $rule->currency_id, 'order_pricing_class_snapshot' => $order->pricing_class,
            'suggested_unit_fee' => $rule->delivery_unit_fee, 'applied_unit_fee' => $rule->delivery_unit_fee,
            'captain_count_snapshot' => $assignments->count(), 'captain_share_percent_snapshot' => $share,
            'gross_delivery_total' => (string) $gross, 'discount_amount' => '0',
            'customer_delivery_total' => (string) $gross, 'status' => DeliveryPricingStatus::Priced,
            'priced_at' => now(), 'created_by' => $actor->id,
        ]);
        foreach (app(DeliveryAllocationService::class)->calculate($rule->delivery_unit_fee, $share, '0', $rule->currency, $assignments) as $row) {
            $pricing->allocations()->create([...$row, 'currency_id' => $rule->currency_id, 'captain_share_percent_snapshot' => $share]);
        }

        return $pricing;
    }
}
