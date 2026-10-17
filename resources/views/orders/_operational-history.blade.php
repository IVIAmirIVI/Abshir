@php
    $events = collect();
    foreach ($order->shops as $shop) {
        foreach (\App\Services\OrderShopProgressService::MARKERS as $marker) {
            if ($shop->{$marker}) {
                $events->push(['at' => $shop->{$marker}, 'label' => $shop->shop_name_snapshot.' · '.\App\Support\UiLabels::label('orders.progress_markers', $marker)]);
            }
        }
        if ($shop->purchaseConfirmation) {
            $events->push(['at' => $shop->purchaseConfirmation->purchased_at, 'label' => $shop->shop_name_snapshot.' · '.__('orders.ui_verified_purchase')]);
        }
        foreach ($shop->receipts as $receipt) {
            $events->push(['at' => $receipt->uploaded_at, 'label' => $shop->shop_name_snapshot.' · '.__('orders.ui_receipts').' · '.$receipt->original_filename]);
        }
    }
    if (auth()->user()->can('invoices.view')) {
        foreach ($order->invoices as $invoice) {
            foreach (['issued_at' => 'issued', 'voided_at' => 'voided', 'replaced_at' => 'replaced'] as $field => $label) {
                if ($invoice->{$field}) {
                    $events->push(['at' => $invoice->{$field}, 'label' => $invoice->invoice_number.' · '.__('financial.ui.'.$label)]);
                }
            }
            if ($invoice->collection) {
                $events->push(['at' => $invoice->collection->collected_at, 'label' => $invoice->invoice_number.' · '.__('financial.collection_states.collected')]);
            }
        }
    }
@endphp
<details class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><summary class="cursor-pointer font-semibold text-brand-primary">{{ __('orders.ui_operational_history') }}</summary><div class="mt-4 space-y-2">
    @foreach($events->sortBy('at') as $event)<p class="text-sm"><x-ltr>{{ \App\Support\UiDate::datetime($event['at'], true) }}</x-ltr> · {{ $event['label'] }}</p>@endforeach
</div></details>
@include('orders._captain-selection-history')
