<?php

namespace App\Services;

use App\Enums\OrderBillPaymentRequestStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderCancellationFinalizationService
{
    public function __construct(private readonly OrderCancellationSafetyService $safety) {}

    public function finalize(User $actor, Order $order): OrderCancellation
    {
        Gate::forUser($actor)->authorize('orders.cancel.finalize');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);

        return DB::transaction(function () use ($actor, $order): OrderCancellation {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->safety->assertNoFinancialCorrectionRequiredLocked($order);
            if (in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['order' => 'Delivered or cancelled Orders cannot be finalized again.']);
            }
            $intent = $order->cancellationIntent()->lockForUpdate()->first();
            if (! $intent || $intent->classification !== OrderCancellationClassification::AfterPurchase || ! $intent->frozen_at) {
                throw ValidationException::withMessages(['order' => 'An accepted AFTER_PURCHASE cancellation intent is required.']);
            }
            $this->safety->assertNoLegacyPurchaseCandidateLocked($order);
            if ($order->billPaymentRequests()->where('status', OrderBillPaymentRequestStatus::Requested->value)->exists()) {
                throw ValidationException::withMessages(['order' => 'Pending bill-payment requests must be cancelled first.']);
            }
            $required = $intent->requiredShops()->orderBy('order_shop_id')->lockForUpdate()->get();
            $purchases = $order->purchaseConfirmations()->orderBy('order_shop_id')->get();
            if ($required->isEmpty() || $required->pluck('purchase_confirmation_id')->sort()->values()->all() !== $purchases->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['order' => 'The frozen purchased-Shop set is incomplete.']);
            }
            foreach ($required as $shop) {
                if (! $order->shopReturns()->where('order_cancellation_intent_id', $intent->id)
                    ->where('order_shop_id', $shop->order_shop_id)
                    ->where('purchase_confirmation_id', $shop->purchase_confirmation_id)
                    ->whereNotNull('sealed_at')->exists()) {
                    throw ValidationException::withMessages(['returns' => 'Every purchased Shop needs a completed evidenced return.']);
                }
            }
            $previous = $order->operational_status;
            $cancellationId = DB::table('order_cancellations')->insertGetId([
                'order_id' => $order->id, 'classification' => OrderCancellationClassification::AfterPurchase->value,
                'reason' => $intent->reason->value, 'notes' => $intent->notes,
                'cancelled_by' => $actor->id, 'cancelled_at' => DB::raw('clock_timestamp()'),
                'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $cancellation = OrderCancellation::query()->findOrFail($cancellationId);
            $order->forceFill(['operational_status' => OrderOperationalStatus::Cancelled])->save();
            DB::table('order_status_history')->insert([
                'order_id' => $order->id, 'previous_status' => $previous->value,
                'new_status' => OrderOperationalStatus::Cancelled->value,
                'changed_by' => $actor->id, 'changed_at' => DB::raw('clock_timestamp()'),
                'reason' => 'Cancelled: AFTER_PURCHASE / '.$intent->reason->value,
                'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
            ]);

            return $cancellation;
        }, 3);
    }
}
