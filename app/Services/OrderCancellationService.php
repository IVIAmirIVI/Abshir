<?php

namespace App\Services;

use App\Enums\OrderBillPaymentRequestStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderCancellationService
{
    public function __construct(private readonly OrderCancellationSafetyService $safety) {}

    public function cancel(User $actor, Order $order, OrderCancellationClassification $classification, OrderCancellationReason $reason, ?string $notes): OrderCancellation
    {
        Gate::forUser($actor)->authorize('orders.cancel');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);

        return DB::transaction(function () use ($actor, $order, $classification, $reason, $notes): OrderCancellation {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['order' => __('domain.delivered_or_already_cancelled_orders_cannot_be_cancelled')]);
            }
            if ($classification !== OrderCancellationClassification::BeforePurchase) {
                throw ValidationException::withMessages(['classification' => __('domain.after_purchase_requires_cancellation_intent_and_completed_shop_returns')]);
            }
            $this->safety->assertNoIntentLocked($order);
            $this->safety->assertNoFinancialCorrectionRequiredLocked($order);
            $hasPurchaseEvidence = $order->shops()->whereNotNull('picked_up_at')->exists() || $order->shops()->whereHas('receipts')->exists();
            if ($hasPurchaseEvidence || $order->purchaseConfirmations()->exists()) {
                throw ValidationException::withMessages(['classification' => __('domain.before_purchase_conflicts_with_existing_pickup_or_receipt_evidence')]);
            }
            $previous = $order->operational_status;
            $cancellation = $order->cancellation()->create(['classification' => $classification, 'reason' => $reason, 'notes' => $notes, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);
            if (app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($order)) {
                foreach ($order->billPaymentRequests()->where('status', OrderBillPaymentRequestStatus::Requested->value)
                    ->orderBy('id')->lockForUpdate()->get() as $request) {
                    $request->update(['status' => OrderBillPaymentRequestStatus::Cancelled,
                        'cancelled_at' => $cancellation->cancelled_at, 'cancelled_by' => $actor->id,
                        'cancellation_reason' => 'ORDER_CANCELLATION #'.$cancellation->id]);
                }
            }
            $order->forceFill(['operational_status' => OrderOperationalStatus::Cancelled])->save();
            $order->statusHistory()->create(['previous_status' => $previous, 'new_status' => OrderOperationalStatus::Cancelled, 'changed_by' => $actor->id, 'changed_at' => $cancellation->cancelled_at, 'reason' => 'Cancelled: '.$classification->value.' / '.$reason->value]);

            return $cancellation;
        });
    }
}
