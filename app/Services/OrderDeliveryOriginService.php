<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Order;
use Illuminate\Support\Collection;

class OrderDeliveryOriginService
{
    public function routes(Order $order, bool $lock = false): Collection
    {
        $query = $order->shops()->with('originArea')->orderBy('sort_order')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->map(fn ($shop): array => [
            'shop' => $shop,
            'origin' => $shop->originArea,
        ]);
    }

    public function resolve(Order $order): ?Area
    {
        $routes = $this->routes($order);
        if ($routes->isEmpty()) {
            return null;
        }

        $origins = $routes->pluck('origin')->filter();
        if ($origins->count() !== $routes->count() || $origins->pluck('id')->unique()->count() !== 1) {
            return null;
        }

        return $origins->first();
    }

    public function issue(Order $order): ?string
    {
        $routes = $this->routes($order);
        if ($routes->isEmpty()) {
            return 'NO_SHOPS';
        }

        if ($routes->contains(fn (array $route): bool => $route['origin'] === null)) {
            return 'MISSING_AREA';
        }

        return null;
    }
}
