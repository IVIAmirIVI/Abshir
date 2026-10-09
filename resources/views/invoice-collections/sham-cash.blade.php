<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('financial.ui.sham_reconciliation') }}</h2></x-slot>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6">
        <p class="rounded bg-emerald-50 p-4">{{ __('financial.ui.sham_help') }}</p>
        <form class="grid gap-3 rounded bg-white p-4 shadow sm:grid-cols-3">
            <label class="space-y-1"><span>{{ __('financial.ui.from_date') }}</span><x-text-input name="from" type="date" :value="request('from')" class="w-full" dir="ltr"/></label>
            <label class="space-y-1"><span>{{ __('financial.ui.to_date') }}</span><x-text-input name="to" type="date" :value="request('to')" class="w-full" dir="ltr"/></label>
            <x-primary-button class="self-end">{{ __('common.actions.filter') }}</x-primary-button>
        </form>
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @forelse($totals as $total)<div class="rounded bg-white p-4 shadow"><p class="text-sm text-gray-600">{{ __('financial.ui.filtered_total') }}</p><p class="text-xl font-bold"><x-money :amount="$total->total" /> <x-ltr>{{ $total->code }}</x-ltr></p></div>@empty<p>{{ __('financial.ui.no_sham_totals') }}</p>@endforelse
        </section>
        <div class="grid gap-4 lg:grid-cols-2">
            @forelse($collections as $collection)
                <article class="space-y-1 rounded bg-white p-5 shadow">
                    <p class="text-sm"><x-ltr>{{ \App\Support\UiDate::datetime($collection->collected_at) }}</x-ltr></p>
                    <a class="font-semibold underline" href="{{ route('orders.invoices.show', [$collection->order, $collection->invoice]) }}"><x-ltr>{{ $collection->invoice->invoice_number }}</x-ltr></a>
                    <p>{{ __('common.terms.order') }} <x-ltr>{{ $collection->order->order_number }}</x-ltr> · {{ $collection->invoice->customer_name_snapshot }}</p>
                    <p>{{ __('common.terms.branch') }}: {{ $collection->order->branch->name }}</p>
                    <p>{{ __('financial.ui.items') }} <x-money :amount="$collection->invoice->items_subtotal" /> <x-ltr>{{ $collection->currency->code }}</x-ltr></p>
                    <p>{{ __('financial.ui.delivery') }} <x-money :amount="$collection->invoice->delivery_total" /> <x-ltr>{{ $collection->currency->code }}</x-ltr></p>
                    <p class="font-bold">{{ __('financial.ui.total') }} <x-money :amount="$collection->invoice->grand_total" /> <x-ltr>{{ $collection->currency->code }}</x-ltr></p>
                    <p class="break-all">{{ __('financial.ui.reference') }}: <x-ltr>{{ $collection->payment_reference ?: '—' }}</x-ltr></p>
                    <p class="text-sm">{{ __('financial.ui.recorded_by') }} {{ $collection->recordedBy?->name }}</p>
                </article>
            @empty
                <p>{{ __('financial.ui.no_sham_collections') }}</p>
            @endforelse
        </div>
        {{ $collections->links() }}
    </div>
</x-app-layout>
