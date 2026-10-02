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
    public function __construct(private readonly CorrectedOrderFinancialService $financials, private readonly OrderMutationGuard $mutationGuard) {}

    public function transition(User $actor, Order $order, OrderOperationalStatus $target, ?string $reason): void
    {
        Gate::forUser($actor)->authorize('orders.status.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        DB::transaction(function () use ($actor, $order, $target, $reason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertNoCancellationIntentLocked($order);
            $previous = $order->operational_status;
            if ($previous->next() !== $target) {
                throw ValidationException::withMessages(['operational_status' => __('domain.this_operational_status_transition_is_not_allowed')]);
            } if ($target === OrderOperationalStatus::CaptainAssigned && ! $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', 'PRIMARY_COLLECTION')->exists()) {
                throw ValidationException::withMessages(['operational_status' => __('domain.assign_a_primary_collection_captain_first')]);
            } if ($target === OrderOperationalStatus::Delivered) {
                if (! $order->shops()->exists()) {
                    throw ValidationException::withMessages(['operational_status' => __('domain.at_least_one_shop_is_required_before_delivery')]);
                }
                $missingEvidence = $order->shops()->where(function ($shops): void {
                    $shops->whereNull('picked_up_at')->orWhereDoesntHave('receipts');
                })->exists();
                if ($missingEvidence) {
                    throw ValidationException::withMessages(['operational_status' => __('domain.every_shop_must_be_picked_up_and_have_receipt_evidence_before_delivery')]);
                }
            } $order->forceFill(['operational_status' => $target])->save();
            $order->statusHistory()->create(['previous_status' => $previous, 'new_status' => $target, 'changed_by' => $actor->id, 'changed_at' => now(), 'reason' => $reason]);
            if ($target === OrderOperationalStatus::Delivered) {
                $this->financials->postIfReady($order);
            }
        });
    }
}
