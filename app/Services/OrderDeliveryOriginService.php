<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Order;

class OrderDeliveryOriginService
{
    public function resolve(Order $order): ?Area
    {
        $shops = $order->shops()->with(['originArea', 'shop.area'])->orderBy('sort_order')->orderBy('id')->get();
        if ($shops->isEmpty()) {
            return null;
        }

        $areaIds = $shops->map(fn ($shop) => $shop->origin_area_id ?? $shop->shop?->area_id)->filter();
        if ($areaIds->count() !== $shops->count() || $areaIds->unique()->count() !== 1) {
            return null;
        }

        return Area::query()->find($areaIds->first());
    }

    public function issue(Order $order): ?string
    {
        $shops = $order->shops()->with(['originArea', 'shop.area'])->orderBy('sort_order')->orderBy('id')->get();
        if ($shops->isEmpty()) {
            return 'NO_SHOPS';
        }
        $areaIds = $shops->map(fn ($shop) => $shop->origin_area_id ?? $shop->shop?->area_id)->filter();
        if ($areaIds->count() !== $shops->count()) {
            return 'MISSING_AREA';
        }

        return $areaIds->unique()->count() === 1 ? null : 'MULTIPLE_AREAS';
    }
}
