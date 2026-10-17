<?php

namespace App\Services;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCaptainRole;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderCaptainService
{
    public function __construct(private readonly OrderDeliveryPricingService $deliveryPricing, private readonly OrderMutationGuard $mutationGuard) {}

    public function eligibleForOrder(User $actor, Order $order): Collection
    {
        Gate::forUser($actor)->authorize('orders.view');
        $this->access($actor, $order);

        return $this->eligibleForBranch($actor, $order->branch);
    }

    /** The same eligibility used for assignment, available before an Order exists. */
    public function eligibleForBranch(User $actor, Branch $branch): Collection
    {
        Gate::forUser($actor)->authorize('orders.view');
        abort_unless($actor->canAccessBranch($branch), 403);

        return Captain::query()->accessibleTo($actor)->with(['user', 'employmentPeriods', 'branchAssignments'])
            ->where('operational_status', CaptainOperationalStatus::Available->value)
            ->whereHas('user', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('employmentPeriods', fn (Builder $query) => $query->whereNull('ended_at'))
            ->get()
            ->filter(fn (Captain $captain) => $captain->activeOperationalBranches()->contains('id', $branch->id))
            ->values();
    }

    public function assign(User $actor, Order $order, Captain $captain, OrderCaptainRole $role): OrderCaptain
    {
        Gate::forUser($actor)->authorize('orders.captains.assign');
        $this->access($actor, $order);
        $this->eligible($captain, $order);

        return DB::transaction(function () use ($actor, $order, $captain, $role): OrderCaptain {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $order->accounting_contract_version === 3 && $order->captainAssignments()->whereNull('removed_at')->exists()) {
                throw ValidationException::withMessages(['captain_id' => __('financial.accounting_v3_single_only')]);
            }
            $this->mutationGuard->assertMutableLocked($order);
            $this->assertLegacySelectionAllowed($order);
            if ($order->captainAssignments()->whereNull('removed_at')->where('captain_id', $captain->id)->exists()) {
                throw ValidationException::withMessages(['captain_id' => __('domain.captain_is_already_assigned')]);
            } $hasPrimary = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->exists();
            if (($role === OrderCaptainRole::PrimaryCollection && $hasPrimary) || ($role === OrderCaptainRole::Support && ! $hasPrimary)) {
                throw ValidationException::withMessages(['assignment_role' => $hasPrimary ? __('domain.primary_exists') : __('domain.primary_first')]);
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
            $this->mutationGuard->assertMutableLocked($order);
            $this->assertLegacySelectionAllowed($order);
            $assignment = OrderCaptain::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($assignment->id);
            if ($assignment->removed_at) {
                throw ValidationException::withMessages(['assignment' => __('domain.assignment_is_already_removed')]);
            } if ($assignment->assignment_role === OrderCaptainRole::PrimaryCollection && $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::Support->value)->exists()) {
                throw ValidationException::withMessages(['assignment' => __('domain.switch_the_primary_collection_captain_before_removing_this_assignment')]);
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
            $this->mutationGuard->assertMutableLocked($order);
            $this->assertLegacySelectionAllowed($order);
            $current = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->lockForUpdate()->firstOrFail();
            if ($current->captain_id === $captain->id) {
                throw ValidationException::withMessages(['captain_id' => __('domain.captain_is_already_primary_collection')]);
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
            throw ValidationException::withMessages(['captain_id' => __('domain.captain_is_not_currently_eligible_for_this_order_branch')]);
        }
    }

    private function access(User $actor, Order $order): void
    {
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
    }

    private function assertLegacySelectionAllowed(Order $order): void
    {
        if (Schema::hasTable('order_captain_selections') && $order->captainSelections()->exists()) {
            throw ValidationException::withMessages(['captain_id' => __('shared_orders.use_selection')]);
        }
    }
}
