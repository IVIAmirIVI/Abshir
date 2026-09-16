<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Customer collections</h2></x-slot>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6">
        <form class="grid gap-3 rounded bg-white p-4 shadow sm:grid-cols-2 lg:grid-cols-4">
            <select name="branch_id" class="rounded border-gray-300"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
            <x-text-input name="from" type="date" :value="request('from')"/><x-text-input name="to" type="date" :value="request('to')"/><x-primary-button>Filter</x-primary-button>
        </form>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach($collections as $collection)
                <article class="rounded bg-white p-5 shadow">
                    <a class="font-semibold underline" href="{{ route('orders.invoices.show', [$collection->order, $collection->invoice]) }}">{{ $collection->invoice->invoice_number }}</a>
                    <p>{{ $collection->order->order_number }} · {{ $collection->invoice->customer_name_snapshot }}</p>
                    <p>{{ $collection->order->branch->name }} · <strong>{{ $collection->collection_method?->value ?? 'Legacy method unknown' }}</strong></p>
                    @if($collection->collection_method === \App\Enums\InvoiceCollectionMethod::Cash)<p>Collector: {{ $collection->captain?->user?->name }}</p>@elseif($collection->collection_method === \App\Enums\InvoiceCollectionMethod::ShamCash)<p>Direct to company Sham Cash</p>@endif
                    <p>{{ $collection->amount }} {{ $collection->currency->code }} · {{ $collection->collected_at->format('Y-m-d H:i') }}</p>
                    @if($collection->payment_reference)<p class="break-all text-sm">Reference: {{ $collection->payment_reference }}</p>@endif
                    <p class="text-sm">Recorded by {{ $collection->recordedBy?->name }}</p>
                </article>
            @endforeach
        </div>
        {{ $collections->links() }}
    </div>
</x-app-layout>
