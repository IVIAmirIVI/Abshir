<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderMutationGuard
{
    public function isOperationalEvidenceMutable(Order $order): bool
    {
        return ! in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true)
            && ! (Schema::hasTable('order_cancellation_intents') && $order->cancellationIntent()->exists());
    }

    public function isPricingFrozen(Order $order): bool
    {
        return ! $this->isOperationalEvidenceMutable($order)
            || $order->currentInvoice()->where('status', InvoiceStatus::Issued->value)->exists();
    }

    public function assertMutableLocked(Order $order): void
    {
        $this->assertNoCancellationIntentLocked($order);
        if ($order->operational_status === OrderOperationalStatus::Delivered) {
            throw ValidationException::withMessages(['order' => __('domain.a_delivered_order_cannot_be_changed')]);
        }

        if ($order->operational_status === OrderOperationalStatus::Cancelled) {
            throw ValidationException::withMessages(['order' => __('domain.a_cancelled_order_cannot_be_changed')]);
        }

        if ($order->currentInvoice()->where('status', InvoiceStatus::Issued->value)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['order' => __('domain.the_order_cannot_be_changed_while_its_current_invoice_is_issued')]);
        }
    }

    public function assertOperationalEvidenceMutableLocked(Order $order): void
    {
        $this->assertNoCancellationIntentLocked($order);
        if ($order->operational_status === OrderOperationalStatus::Delivered) {
            throw ValidationException::withMessages(['order' => __('domain.a_delivered_order_cannot_be_changed')]);
        }

        if ($order->operational_status === OrderOperationalStatus::Cancelled) {
            throw ValidationException::withMessages(['order' => __('domain.a_cancelled_order_cannot_be_changed')]);
        }
    }

    public function assertNoCancellationIntentLocked(Order $order): void
    {
        if (Schema::hasTable('order_cancellation_intents') && $order->cancellationIntent()->exists()) {
            throw ValidationException::withMessages(['order' => __('domain.normal_order_advancement_is_frozen_by_an_after_purchase_cancellation_intent')]);
        }
    }
}
