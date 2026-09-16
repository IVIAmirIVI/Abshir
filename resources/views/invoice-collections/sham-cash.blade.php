<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Sham Cash reconciliation</h2></x-slot>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6">
        <p class="rounded bg-emerald-50 p-4">Customer money shown here was transferred directly to the company owner's Sham Cash account. It is not Captain cash custody.</p>
        <form class="grid gap-3 rounded bg-white p-4 shadow sm:grid-cols-3">
            <label class="space-y-1"><span>From date</span><x-text-input name="from" type="date" :value="request('from')" class="w-full"/></label>
            <label class="space-y-1"><span>To date</span><x-text-input name="to" type="date" :value="request('to')" class="w-full"/></label>
            <x-primary-button class="self-end">Filter</x-primary-button>
        </form>
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @forelse($totals as $total)<div class="rounded bg-white p-4 shadow"><p class="text-sm text-gray-600">Filtered total</p><p class="text-xl font-bold">{{ $total->total }} {{ $total->code }}</p></div>@empty<p>No Sham Cash totals for this period.</p>@endforelse
        </section>
        <div class="grid gap-4 lg:grid-cols-2">
            @forelse($collections as $collection)
                <article class="space-y-1 rounded bg-white p-5 shadow">
                    <p class="text-sm">{{ $collection->collected_at->format('Y-m-d H:i') }}</p>
                    <a class="font-semibold underline" href="{{ route('orders.invoices.show', [$collection->order, $collection->invoice]) }}">{{ $collection->invoice->invoice_number }}</a>
                    <p>Order {{ $collection->order->order_number }} · {{ $collection->invoice->customer_name_snapshot }}</p>
                    <p>Branch: {{ $collection->order->branch->name }}</p>
                    <p>Items: {{ $collection->invoice->items_subtotal }} {{ $collection->currency->code }}</p>
                    <p>Delivery: {{ $collection->invoice->delivery_total }} {{ $collection->currency->code }}</p>
                    <p class="font-bold">Total: {{ $collection->invoice->grand_total }} {{ $collection->currency->code }}</p>
                    <p class="break-all">Reference: {{ $collection->payment_reference ?: '—' }}</p>
                    <p class="text-sm">Recorded by {{ $collection->recordedBy?->name }}</p>
                </article>
            @empty
                <p>No Sham Cash collections found.</p>
            @endforelse
        </div>
        {{ $collections->links() }}
    </div>
</x-app-layout>
