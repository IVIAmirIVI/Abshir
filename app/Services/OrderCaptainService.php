<?php

namespace App\Services;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCaptainRole;
use App\Models\Captain;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderCaptainService
{
    public function __construct(private readonly OrderDeliveryPricingService $deliveryPricing) {}

    public function assign(User $actor, Order $order, Captain $captain, OrderCaptainRole $role): OrderCaptain
    {
        Gate::forUser($actor)->authorize('orders.captains.assign');
        $this->access($actor, $order);
        $this->eligible($captain, $order);

        return DB::transaction(function () use ($actor, $order, $captain, $role): OrderCaptain {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->captainAssignments()->whereNull('removed_at')->where('captain_id', $captain->id)->exists()) {
                throw ValidationException::withMessages(['captain_id' => 'Captain is already assigned.']);
            } $hasPrimary = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->exists();
            if (($role === OrderCaptainRole::PrimaryCollection && $hasPrimary) || ($role === OrderCaptainRole::Support && ! $hasPrimary)) {
                throw ValidationException::withMessages(['assignment_role' => $hasPrimary ? 'The Order already has a Primary Collection Captain.' : 'Assign a Primary Collection Captain before Support Captains.']);
            }

            $assignment = $order->captainAssignments()->create(['captain_id' => $captain->id, 'assignment_role' => $role, 'assigned_at' => now(), 'assigned_by' => $actor->id]);
            $this->deliveryPricing->repriceAfterCaptainChangeLocked($actor, $order);

            return $assignment;
        });
    }

    public function remove(User $actor, Order $order, OrderCaptain $assignment, string $reason): void
    {
        Gate::forUser($actor)->authorize('orders.captains.remove');
        $this->access($actor, $order);
        abort_if($assignment->order_id !== $order->id, 404);
        DB::transaction(function () use ($actor, $order, $assignment, $reason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $assignment = OrderCaptain::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($assignment->id);
            if ($assignment->removed_at) {
                throw ValidationException::withMessages(['assignment' => 'Assignment is already removed.']);
            } if ($assignment->assignment_role === OrderCaptainRole::PrimaryCollection && $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::Support->value)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'Switch the Primary Collection Captain before removing this assignment.']);
            } $assignment->update(['removed_at' => now(), 'removed_by' => $actor->id, 'removal_reason' => $reason]);
            $this->deliveryPricing->repriceAfterCaptainChangeLocked($actor, $order);
        });
    }

    public function switchPrimary(User $actor, Order $order, Captain $captain, string $reason): OrderCaptain
    {
        Gate::forUser($actor)->authorize('orders.captains.assign');
        Gate::forUser($actor)->authorize('orders.captains.remove');
        $this->access($actor, $order);
        $this->eligible($captain, $order);

        return DB::transaction(function () use ($actor, $order, $captain, $reason): OrderCaptain {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $current = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->lockForUpdate()->firstOrFail();
            if ($current->captain_id === $captain->id) {
                throw ValidationException::withMessages(['captain_id' => 'Captain is already Primary Collection.']);
            } $target = $order->captainAssignments()->whereNull('removed_at')->where('captain_id', $captain->id)->lockForUpdate()->first();
            $current->update(['removed_at' => now(), 'removed_by' => $actor->id, 'removal_reason' => 'Primary switched: '.$reason]);
            if ($target) {
                $target->update(['removed_at' => now(), 'removed_by' => $actor->id, 'removal_reason' => 'Promoted to Primary Collection: '.$reason]);
            }

            $assignment = $order->captainAssignments()->create(['captain_id' => $captain->id, 'assignment_role' => OrderCaptainRole::PrimaryCollection, 'assigned_at' => now(), 'assigned_by' => $actor->id]);
            $this->deliveryPricing->repriceAfterCaptainChangeLocked($actor, $order);

            return $assignment;
        });
    }

    private function eligible(Captain $captain, Order $order): void
    {
        if (! $captain->isEmployed() || ! $captain->user->is_active || $captain->operational_status !== CaptainOperationalStatus::Available || ! $captain->activeOperationalBranches()->contains('id', $order->branch_id)) {
            throw ValidationException::withMessages(['captain_id' => 'Captain is not currently eligible for this Order Branch.']);
        }
    }

    private function access(User $actor, Order $order): void
    {
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
    }
}
