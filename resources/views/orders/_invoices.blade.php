<section class="space-y-4 rounded bg-white p-5 shadow">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h3 class="font-semibold">Customer Invoice</h3><p class="text-sm">Financially complete: {{ $order->isFinanciallyComplete() ? 'Yes' : 'No' }}</p></div>
        @can('invoices.create')
            @if(!$order->currentInvoice)
                <form method="POST" action="{{ route('orders.invoices.store', $order) }}">@csrf<x-primary-button>Create Invoice draft</x-primary-button></form>
            @endif
        @endcan
    </div>
    @forelse($order->invoices->sortByDesc('version') as $invoice)
        <article class="rounded border p-3">
            <div class="flex flex-wrap justify-between gap-2"><a class="font-semibold underline" href="{{ route('orders.invoices.show', [$order, $invoice]) }}">{{ $invoice->invoice_number }} · v{{ $invoice->version }}</a><span>{{ $invoice->status->value }}</span></div>
            <p>{{ $invoice->grand_total ?? 'Not ready' }} {{ $invoice->currency?->code }} · {{ $invoice->collection ? 'Collected '.$invoice->collection->collected_at->format('Y-m-d H:i') : 'Uncollected' }}</p>
            @if($invoice->issued_at)<p class="text-sm">Issued {{ $invoice->issued_at->format('Y-m-d H:i') }}</p>@endif
            @if($invoice->void_reason)<p class="text-sm">Voided: {{ $invoice->void_reason }}</p>@endif
            @if($invoice->replacement_reason)<p class="text-sm">Replaced: {{ $invoice->replacement_reason }}</p>@endif
        </article>
    @empty
        <p>No Invoice versions yet.</p>
    @endforelse
</section>
