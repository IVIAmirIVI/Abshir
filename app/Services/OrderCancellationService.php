<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
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
    public function cancel(User $actor, Order $order, OrderCancellationClassification $classification, OrderCancellationReason $reason, ?string $notes): OrderCancellation
    {
        Gate::forUser($actor)->authorize('orders.cancel');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);

        return DB::transaction(function () use ($actor, $order, $classification, $reason, $notes): OrderCancellation {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['order' => 'Delivered or already cancelled Orders cannot be cancelled.']);
            }
            if ($order->currentInvoice()->where('status', InvoiceStatus::Issued->value)->lockForUpdate()->first() || $order->invoices()->whereHas('collection')->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => 'An issued or collected Invoice requires a future reversal workflow before cancellation.']);
            }
            $hasPurchaseEvidence = $order->shops()->whereNotNull('picked_up_at')->exists() || $order->shops()->whereHas('receipts')->exists();
            if ($classification === OrderCancellationClassification::BeforePurchase && $hasPurchaseEvidence) {
                throw ValidationException::withMessages(['classification' => 'BEFORE_PURCHASE conflicts with existing pickup or receipt evidence.']);
            }
            if ($classification === OrderCancellationClassification::AfterPurchase && ! $hasPurchaseEvidence) {
                throw ValidationException::withMessages(['classification' => 'AFTER_PURCHASE requires pickup or receipt evidence.']);
            }
            $previous = $order->operational_status;
            $cancellation = $order->cancellation()->create(['classification' => $classification, 'reason' => $reason, 'notes' => $notes, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);
            $order->forceFill(['operational_status' => OrderOperationalStatus::Cancelled])->save();
            $order->statusHistory()->create(['previous_status' => $previous, 'new_status' => OrderOperationalStatus::Cancelled, 'changed_by' => $actor->id, 'changed_at' => $cancellation->cancelled_at, 'reason' => 'Cancelled: '.$classification->value.' / '.$reason->value]);

            return $cancellation;
        });
    }
}
