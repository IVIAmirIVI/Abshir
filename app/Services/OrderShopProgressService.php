<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderShopProgressService
{
    public const MARKERS = ['contacted_at', 'confirmed_at', 'ready_at', 'picked_up_at'];

    public function __construct(private readonly OrderMutationGuard $mutationGuard) {}

    public function record(User $actor, Order $order, OrderShop $orderShop, string $marker): OrderShop
    {
        Gate::forUser($actor)->authorize('orders.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($orderShop->order_id !== $order->id, 404);
        if (! in_array($marker, self::MARKERS, true)) {
            throw ValidationException::withMessages(['marker' => 'Select a valid Shop progress marker.']);
        }

        return DB::transaction(function () use ($order, $orderShop, $marker): OrderShop {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertOperationalEvidenceMutableLocked($order);
            $orderShop = OrderShop::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($orderShop->id);
            if ($orderShop->{$marker}) {
                throw ValidationException::withMessages(['marker' => 'This progress marker is already recorded and cannot be rewritten.']);
            }
            $orderShop->update([$marker => now()]);

            return $orderShop->fresh();
        });
    }
}
