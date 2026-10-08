<?php

namespace App\Services;

use App\Enums\OrderCaptainRole;
use App\Enums\OrderPricingStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceSnapshotService
{
    /** Read the same locked source facts and fingerprint used by issuance, without changing them. */
    public function currentSourcesAreStale(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice): bool {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            try {
                return $this->isStale($invoice, $this->build($order));
            } catch (ValidationException) {
                return true;
            }
        });
    }

    /** Read-only eligibility using the exact production snapshot builder. */
    public function readinessProblem(Order $order): ?string
    {
        return DB::transaction(function () use ($order): ?string {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            try {
                $this->build($order);

                return null;
            } catch (ValidationException $exception) {
                return collect($exception->errors())->flatten()->first();
            }
        });
    }

    public function build(Order $order): array
    {
        $order->refresh();
        if ($order->pricing_status !== OrderPricingStatus::Confirmed) {
            throw ValidationException::withMessages(['invoice' => __('domain.all_order_item_actual_prices_must_be_confirmed_before_the_invoice_can_become_ready')]);
        }
        $items = OrderItem::query()->whereHas('orderShop', fn ($shops) => $shops->where('order_id', $order->id))->orderBy('id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['invoice' => __('domain.the_order_requires_at_least_one_item')]);
        }
        $pricing = $order->currentDeliveryPricing()->with('currency')->lockForUpdate()->first();
        if (! $pricing) {
            throw ValidationException::withMessages(['invoice' => __('domain.current_delivery_pricing_is_required')]);
        }
        $primary = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->lockForUpdate()->first();
        if (! $primary) {
            throw ValidationException::withMessages(['invoice' => __('domain.an_active_primary_collection_captain_is_required')]);
        }

        $currency = $pricing->currency;
        $subtotal = BigDecimal::zero();
        $lines = [];
        $fingerprintItems = [];
        foreach ($items as $index => $item) {
            if ($item->actual_unit_price === null || $item->actual_currency_id === null || $item->actual_price_source === null || $item->actual_price_confirmed_by === null || $item->actual_price_confirmed_at === null) {
                throw ValidationException::withMessages(['invoice' => __('domain.every_invoice_item_requires_complete_actual_price_evidence')]);
            }
            if ($item->actual_currency_id !== $currency->id) {
                throw ValidationException::withMessages(['currency' => __('domain.all_item_actual_prices_and_delivery_pricing_must_use_one_currency')]);
            }
            $lineTotal = BigDecimal::of($item->current_quantity)->multipliedBy(BigDecimal::of($item->actual_unit_price))->toScale($currency->decimal_places, RoundingMode::HalfUp);
            $subtotal = $subtotal->plus($lineTotal);
            $lines[] = ['order_item_id' => $item->id, 'description' => $item->product_name_snapshot, 'unit_name_snapshot' => $item->unit_name_snapshot, 'quantity' => $item->current_quantity, 'unit_price' => $item->actual_unit_price, 'line_total' => (string) $lineTotal->toScale(4), 'currency_id' => $currency->id, 'sort_order' => $index + 1];
            $fingerprintItems[] = [$item->id, $item->product_name_snapshot, $item->unit_name_snapshot, $item->current_quantity, $item->actual_unit_price, $item->actual_currency_id, $item->actual_price_source->value, $item->actual_price_confirmed_by, $item->actual_price_confirmed_at->toJSON(), $item->updated_at?->toJSON()];
        }
        $delivery = BigDecimal::of($pricing->customer_delivery_total);
        if ($delivery->strippedOfTrailingZeros()->getScale() > $currency->decimal_places) {
            throw ValidationException::withMessages(['currency' => __('domain.delivery_total_exceeds_the_currency_minor_unit_precision')]);
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
