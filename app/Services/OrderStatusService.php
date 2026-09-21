<?php

namespace App\Services;

use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    public function transition(User $actor, Order $order, OrderOperationalStatus $target, ?string $reason): void
    {
        Gate::forUser($actor)->authorize('orders.status.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        DB::transaction(function () use ($actor, $order, $target, $reason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $previous = $order->operational_status;
            if ($previous->next() !== $target) {
                throw ValidationException::withMessages(['operational_status' => 'This operational status transition is not allowed.']);
            } if ($target === OrderOperationalStatus::CaptainAssigned && ! $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', 'PRIMARY_COLLECTION')->exists()) {
                throw ValidationException::withMessages(['operational_status' => 'Assign a Primary Collection Captain first.']);
            } if ($target === OrderOperationalStatus::Delivered) {
                if (! $order->shops()->exists()) {
                    throw ValidationException::withMessages(['operational_status' => 'At least one Shop is required before delivery.']);
                }
                $missingEvidence = $order->shops()->where(function ($shops): void {
                    $shops->whereNull('picked_up_at')->orWhereDoesntHave('receipts');
                })->exists();
                if ($missingEvidence) {
                    throw ValidationException::withMessages(['operational_status' => 'Every Shop must be picked up and have receipt evidence before delivery.']);
                }
            } $order->forceFill(['operational_status' => $target])->save();
            $order->statusHistory()->create(['previous_status' => $previous, 'new_status' => $target, 'changed_by' => $actor->id, 'changed_at' => now(), 'reason' => $reason]);
        });
    }
}
