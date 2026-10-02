<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('financial.ui.customer_collections') }}</h2></x-slot>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6">
        <form class="grid gap-3 rounded bg-white p-4 shadow sm:grid-cols-2 lg:grid-cols-4">
            <select name="branch_id" class="min-w-0 max-w-full rounded border-gray-300"><option value="">{{ __('financial.ui.all_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
            <x-text-input name="from" type="date" :value="request('from')" dir="ltr"/><x-text-input name="to" type="date" :value="request('to')" dir="ltr"/><x-primary-button class="w-full justify-center sm:w-auto">{{ __('common.actions.filter') }}</x-primary-button>
        </form>
        <div class="grid gap-4 lg:grid-cols-2">
            @forelse($collections as $collection)
                <article class="rounded bg-white p-5 shadow">
                    <a class="font-semibold underline" href="{{ route('orders.invoices.show', [$collection->order, $collection->invoice]) }}"><x-ltr>{{ $collection->invoice->invoice_number }}</x-ltr></a>
                    <p><x-ltr>{{ $collection->order->order_number }}</x-ltr> · {{ $collection->invoice->customer_name_snapshot }}</p>
                    <p>{{ $collection->order->branch->name }} · <strong>{{ $collection->collection_method ? \App\Support\UiLabels::label('financial.collection_methods', $collection->collection_method) : __('financial.ui.legacy_method') }}</strong></p>
                    @if($collection->collection_method === \App\Enums\InvoiceCollectionMethod::Cash)<p>{{ __('financial.ui.collector') }} {{ $collection->captain?->user?->name }}</p>@elseif($collection->collection_method === \App\Enums\InvoiceCollectionMethod::ShamCash)<p>{{ __('financial.ui.direct_sham') }}</p>@endif
                    <p><x-money :amount="$collection->amount" /> <x-ltr>{{ $collection->currency->code }}</x-ltr> · <x-ltr>{{ $collection->collected_at->format('Y-m-d H:i') }}</x-ltr></p>
                    @if($collection->payment_reference)<p class="break-all text-sm">{{ __('financial.ui.reference') }}: <x-ltr>{{ $collection->payment_reference }}</x-ltr></p>@endif
                    <p class="text-sm">{{ __('financial.ui.recorded_by') }} {{ $collection->recordedBy?->name }}</p>
                </article>
            @empty<p>{{ __('financial.ui.no_collections') }}</p>@endforelse
        </div>
        {{ $collections->links() }}
    </div>
</x-app-layout>
