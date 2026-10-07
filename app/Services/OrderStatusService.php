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

    public function nextTransitionProblem(Order $order): ?string
    {
        $target = $order->operational_status->next();
        if (! $target) {
            return __('domain.this_operational_status_transition_is_not_allowed');
        }

        return $this->transitionProblem($order, $target);
    }

    private function transitionProblem(Order $order, OrderOperationalStatus $target): ?string
    {
        if (! $this->mutationGuard->isOperationalEvidenceMutable($order)) {
            return __('domain.normal_order_advancement_is_frozen_by_an_after_purchase_cancellation_intent');
        }
        if ($order->operational_status->next() !== $target) {
            return __('domain.this_operational_status_transition_is_not_allowed');
        }
        if ($target === OrderOperationalStatus::CaptainAssigned && ! $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', 'PRIMARY_COLLECTION')->exists()) {
            return __('domain.assign_a_primary_collection_captain_first');
        }
        if ($target === OrderOperationalStatus::Delivered) {
            if ($problem = $this->financials->correctionPostingProblem($order)) {
                return $problem;
            }
            if (! $order->shops()->exists()) {
                return __('domain.at_least_one_shop_is_required_before_delivery');
            }
            if ($order->shops()->where(fn ($shops) => $shops->whereNull('picked_up_at')->orWhereDoesntHave('receipts'))->exists()) {
                return __('domain.every_shop_must_be_picked_up_and_have_receipt_evidence_before_delivery');
            }
        }

        return null;
    }

    public function transition(User $actor, Order $order, OrderOperationalStatus $target, ?string $reason): void
    {
        Gate::forUser($actor)->authorize('orders.status.update');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        DB::transaction(function () use ($actor, $order, $target, $reason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertNoCancellationIntentLocked($order);
            $previous = $order->operational_status;
            if ($problem = $this->transitionProblem($order, $target)) {
                throw ValidationException::withMessages(['operational_status' => $problem]);
            }
            $order->forceFill(['operational_status' => $target])->save();
            $order->statusHistory()->create(['previous_status' => $previous, 'new_status' => $target, 'changed_by' => $actor->id, 'changed_at' => now(), 'reason' => $reason]);
            if ($target === OrderOperationalStatus::Delivered) {
                $this->financials->postIfReady($order);
            }
        });
    }
}
