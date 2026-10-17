<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Gate;

class ShopCommissionEligibilityService
{
    /**
     * Lifecycle eligibility only; no earnings, settlement or financial posting.
     *
     * @return array{eligible: bool, reason: string, order_id: int, invoice_id: ?int, collection_id: ?int}
     */
    public function inspect(User $actor, Order $order): array
    {
        Gate::forUser($actor)->authorize('orders.view');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order->id)->exists(), 403);
        $order = $order->fresh();
        $invoice = $order->currentInvoice()->with(['collection', 'settledCorrection.originalCollection'])->first();
        $correction = $invoice?->settledCorrection;
        $collection = $invoice?->collection ?? $correction?->originalCollection;
        $reason = match (true) {
            $order->operational_status !== OrderOperationalStatus::Delivered => 'not_delivered',
            ! $invoice || $invoice->status !== InvoiceStatus::Issued => 'not_issued',
            ! $collection => 'not_collected',
            $collection->order_id !== $order->id || $collection->currency_id !== $invoice->currency_id
                || ! $collection->collected_at || (! $correction && ! BigDecimal::of($collection->amount)->plus(app(MixedCustomerCreditService::class)->creditAmount($collection))->isEqualTo($invoice->grand_total))
                || ($correction && ! BigDecimal::of($correction->corrected_amount)->isEqualTo($invoice->grand_total)) => 'invalid_collection',
            default => 'eligible',
        };

        return ['eligible' => $reason === 'eligible', 'reason' => $reason, 'order_id' => $order->id, 'invoice_id' => $invoice?->id, 'collection_id' => $collection?->id];
    }
}
