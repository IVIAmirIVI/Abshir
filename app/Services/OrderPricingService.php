<?php

namespace App\Services;

use App\Enums\OrderPricingStatus;
use App\Models\Order;

class OrderPricingService
{
    public function recalculate(Order $order): OrderPricingStatus
    {
        $items = $order->shops()->with('items')->get()->flatMap->items;
        $confirmed = $items->filter(fn ($item) => $item->actual_unit_price !== null && $item->actual_currency_id !== null)->count();
        $status = $confirmed === 0 ? OrderPricingStatus::Estimated : ($confirmed === $items->count() ? OrderPricingStatus::Confirmed : OrderPricingStatus::PartiallyConfirmed);
        if ($order->pricing_status !== $status) {
            $order->forceFill(['pricing_status' => $status])->save();
        }

        return $status;
    }
}
