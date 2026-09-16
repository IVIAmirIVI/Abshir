<?php

namespace App\Services;

use App\Enums\OrderCaptainRole;
use App\Enums\OrderPricingStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class InvoiceSnapshotService
{
    public function build(Order $order): array
    {
        $order->refresh();
        if ($order->pricing_status !== OrderPricingStatus::Confirmed) {
            throw ValidationException::withMessages(['invoice' => 'All Order Item actual prices must be confirmed before the Invoice can become READY.']);
        }
        $items = OrderItem::query()->whereHas('orderShop', fn ($shops) => $shops->where('order_id', $order->id))->orderBy('id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['invoice' => 'The Order requires at least one Item.']);
        }
        $pricing = $order->currentDeliveryPricing()->with('currency')->lockForUpdate()->first();
        if (! $pricing) {
            throw ValidationException::withMessages(['invoice' => 'Current Delivery Pricing is required.']);
        }
        $primary = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->lockForUpdate()->first();
        if (! $primary) {
            throw ValidationException::withMessages(['invoice' => 'An active PRIMARY_COLLECTION Captain is required.']);
        }

        $currency = $pricing->currency;
        $subtotal = BigDecimal::zero();
        $lines = [];
        $fingerprintItems = [];
        foreach ($items as $index => $item) {
            if ($item->actual_unit_price === null || $item->actual_currency_id === null || $item->actual_price_source === null || $item->actual_price_confirmed_by === null || $item->actual_price_confirmed_at === null) {
                throw ValidationException::withMessages(['invoice' => 'Every Invoice Item requires complete actual-price evidence.']);
            }
            if ($item->actual_currency_id !== $currency->id) {
                throw ValidationException::withMessages(['currency' => 'All Item actual prices and Delivery Pricing must use one Currency.']);
            }
            $lineTotal = BigDecimal::of($item->current_quantity)->multipliedBy(BigDecimal::of($item->actual_unit_price))->toScale($currency->decimal_places, RoundingMode::HalfUp);
            $subtotal = $subtotal->plus($lineTotal);
            $lines[] = ['order_item_id' => $item->id, 'description' => $item->product_name_snapshot, 'unit_name_snapshot' => $item->unit_name_snapshot, 'quantity' => $item->current_quantity, 'unit_price' => $item->actual_unit_price, 'line_total' => (string) $lineTotal->toScale(4), 'currency_id' => $currency->id, 'sort_order' => $index + 1];
            $fingerprintItems[] = [$item->id, $item->product_name_snapshot, $item->unit_name_snapshot, $item->current_quantity, $item->actual_unit_price, $item->actual_currency_id, $item->actual_price_source->value, $item->actual_price_confirmed_by, $item->actual_price_confirmed_at->toJSON(), $item->updated_at?->toJSON()];
        }
        $delivery = BigDecimal::of($pricing->customer_delivery_total);
        if ($delivery->strippedOfTrailingZeros()->getScale() > $currency->decimal_places) {
            throw ValidationException::withMessages(['currency' => 'Delivery total exceeds the Currency minor-unit precision.']);
        }
        $grand = $subtotal->plus($delivery);

        return [
            'currency' => $currency,
            'pricing' => $pricing,
            'primary' => $primary,
            'lines' => $lines,
            'items_subtotal' => (string) $subtotal->toScale(4),
            'delivery_total' => (string) $delivery->toScale(4),
            'grand_total' => (string) $grand->toScale(4),
            'fingerprint' => hash('sha256', json_encode($fingerprintItems, JSON_THROW_ON_ERROR)),
        ];
    }

    public function isStale(Invoice $invoice, array $snapshot): bool
    {
        return $invoice->source_order_delivery_pricing_id !== $snapshot['pricing']->id || ! hash_equals((string) $invoice->source_items_fingerprint, $snapshot['fingerprint']);
    }
}
