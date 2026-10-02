<?php

namespace App\Services;

use App\Enums\OrderPricingClass;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderPricingClassificationService
{
    public function recalculateLocked(Order $order): ?OrderPricingClass
    {
        $shops = $order->shops()->orderBy('id')->lockForUpdate()->get();
        $classification = match (true) {
            $shops->isEmpty() => null,
            $shops->count() >= 2 => OrderPricingClass::MultiShop,
            $shops->first()->business_classification_snapshot !== null => OrderPricingClass::fromShopClassification($shops->first()->business_classification_snapshot),
            default => throw ValidationException::withMessages(['business_classification' => __('domain.the_order_shop_requires_a_restaurant_or_shop_classification')]),
        };

        if ($order->pricing_class === $classification) {
            return $classification;
        }

        $current = $order->currentDeliveryPricing()->lockForUpdate()->first();
        if ($current) {
            $current->update(['superseded_at' => now()]);
        }
        $order->update(['pricing_class' => $classification?->value]);

        return $classification;
    }
}
