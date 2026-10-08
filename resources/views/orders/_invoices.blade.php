<section class="space-y-4 rounded bg-white p-5 shadow">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h3 class="font-semibold">{{ __('financial.ui.customer_invoice') }}</h3><p class="text-sm">{{ __('orders.ui_invoice_payment_label') }} {{ $order->currentInvoice && app(\App\Services\InvoiceCollectionService::class)->availability(auth()->user(), $order->currentInvoice)['payment_satisfied'] ? __('financial.ui.yes') : __('financial.ui.no') }}</p></div>
        @can('invoices.create')
            @if(!($summaryOnly ?? false) && !$order->currentInvoice && ! app(\App\Services\InvoiceService::class)->mutationProblem($order))
                <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.invoices.store', $order) }}">@csrf<x-primary-button x-bind:disabled="submitting" class="w-full justify-center sm:w-auto">{{ __('financial.ui.create_draft') }}</x-primary-button></form>
            @endif
        @endcan
    </div>
    @forelse($order->invoices->sortByDesc('version') as $invoice)
        <article class="rounded border p-3"><p class="text-sm">{{ $invoice->superseded_at ? __('financial.ui.historical_invoice') : __('financial.ui.current_invoice') }}</p>
            <div class="flex flex-wrap justify-between gap-2"><a class="font-semibold underline" href="{{ route('orders.invoices.show', [$order, $invoice]) }}"><x-ltr>{{ $invoice->invoice_number }}</x-ltr> · {{ __('financial.ui.version') }} <x-ltr>{{ $invoice->version }}</x-ltr></a><span>{{ \App\Support\UiLabels::label('financial.invoice_statuses', $invoice->status) }}</span></div>
            <p>@if($invoice->grand_total !== null)<x-money :amount="$invoice->grand_total" />@else{{ __('financial.ui.not_ready') }}@endif <x-ltr>{{ $invoice->currency?->code }}</x-ltr> · @if($invoice->collection){{ __('financial.collection_states.collected') }} <x-ltr>{{ $invoice->collection->collected_at->format('Y-m-d H:i') }}</x-ltr>@elseif($invoice->settledCorrection()->exists()){{ __('orders.ui_payment_settled_correction') }}@else{{ __('financial.collection_states.uncollected') }}@endif</p>
            @if($invoice->issued_at)<p class="text-sm">{{ __('financial.ui.issued') }} <x-ltr>{{ $invoice->issued_at->format('Y-m-d H:i') }}</x-ltr></p>@endif
            @if($invoice->void_reason)<p class="text-sm">{{ __('financial.ui.voided') }} {{ $invoice->void_reason }}</p>@endif
            @if($invoice->replacement_reason)<p class="text-sm">{{ __('financial.ui.replaced') }} {{ $invoice->replacement_reason }}</p>@endif
        </article>
    @empty
        <p>{{ __('financial.ui.no_versions') }}</p>
    @endforelse
</section>
