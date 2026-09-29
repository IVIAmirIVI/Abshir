<?php

namespace App\Services;

use App\Enums\OrderBillPaymentRequestStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\OrderCancellationIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderCancellationIntentService
{
    public function __construct(private readonly OrderCancellationSafetyService $safety) {}

    public function create(User $actor, Order $order, OrderCancellationReason $reason, ?string $notes, string $requestKey): OrderCancellationIntent
    {
        Gate::forUser($actor)->authorize('order_cancellation_intents.create');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'A UUID request key is required.']);
        }

        return DB::transaction(function () use ($actor, $order, $reason, $notes, $requestKey): OrderCancellationIntent {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = $order->cancellationIntent()->first();
            if ($existing) {
                if ($existing->request_key !== $requestKey || $existing->reason !== $reason
                    || $existing->notes !== $notes || $existing->requested_by !== $actor->id) {
                    throw ValidationException::withMessages(['request_key' => 'Cancellation intent already exists with different facts.']);
                }

                return $existing;
            }
            $this->safety->assertNoFinancialCorrectionRequiredLocked($order);
            if (in_array($order->operational_status, [OrderOperationalStatus::Cancelled, OrderOperationalStatus::Delivered], true)) {
                throw ValidationException::withMessages(['order' => 'Delivered or cancelled Orders cannot start cancellation.']);
            }
            $this->safety->assertNoLegacyPurchaseCandidateLocked($order);
            $purchases = $order->purchaseConfirmations()->orderBy('id')->get();
            if ($purchases->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'AFTER_PURCHASE requires an authoritative purchase confirmation.']);
            }
            $intentId = DB::table('order_cancellation_intents')->insertGetId([
                'order_id' => $order->id, 'classification' => OrderCancellationClassification::AfterPurchase->value,
                'reason' => $reason->value, 'notes' => $notes, 'requested_by' => $actor->id,
                'intent_at' => DB::raw('clock_timestamp()'), 'request_key' => $requestKey,
                'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $intent = OrderCancellationIntent::query()->findOrFail($intentId);
            foreach ($purchases as $purchase) {
                $intent->requiredShops()->create(['order_shop_id' => $purchase->order_shop_id,
                    'purchase_confirmation_id' => $purchase->id]);
            }
            foreach ($order->billPaymentRequests()->where('status', OrderBillPaymentRequestStatus::Requested->value)
                ->orderBy('id')->lockForUpdate()->get() as $request) {
                $request->update(['status' => OrderBillPaymentRequestStatus::Cancelled,
                    'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                    'cancellation_reason' => 'ORDER_CANCELLATION_INTENT #'.$intent->id]);
            }
            DB::table('order_cancellation_intents')->where('id', $intentId)->update(['frozen_at' => DB::raw('clock_timestamp()')]);

            return $intent->fresh();
        }, 3);
    }
}
