<?php

namespace App\Services;

use App\Enums\OrderShopType;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderShopService
{
    public function add(User $actor, Order $order, array $data): OrderShop
    {
        Gate::forUser($actor)->authorize('orders.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        $type = OrderShopType::from($data['shop_type']);
        if ($type === OrderShopType::Registered) {
            $shop = Shop::query()->with('area')->findOrFail($data['shop_id']);
            abort_unless($actor->canAccessShop($shop), 403);
            if ($shop->area && $shop->area->branch_id !== $order->branch_id) {
                throw ValidationException::withMessages(['shop_id' => 'The Shop is outside the Order Branch.']);
            } if (! $shop->is_active) {
                throw ValidationException::withMessages(['shop_id' => 'An inactive Shop cannot be added to a new Order.']);
            }

return $order->shops()->create(['shop_type' => $type, 'shop_id' => $shop->id, 'shop_name_snapshot' => $shop->name, 'shop_phone_snapshot' => $shop->phone, 'shop_address_snapshot' => $shop->address, 'area_name_snapshot' => $shop->area?->name, 'notes' => $data['notes'] ?? null, 'sort_order' => $data['sort_order'] ?? 0]);
        }
        if (! empty($data['shop_id'])) {
            throw ValidationException::withMessages(['shop_id' => 'A one-time Shop cannot reference a registered Shop.']);
        }

        return $order->shops()->create(['shop_type' => $type, 'shop_id' => null, 'shop_name_snapshot' => $data['shop_name'], 'shop_phone_snapshot' => $data['shop_phone'] ?? null, 'shop_address_snapshot' => $data['shop_address'] ?? null, 'notes' => $data['notes'] ?? null, 'sort_order' => $data['sort_order'] ?? 0]);
    }
}
