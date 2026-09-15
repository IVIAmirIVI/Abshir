<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 break-words">Shop price comparison: {{ $product->name }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5 min-w-0">
        <p class="rounded-lg bg-blue-50 p-4 text-sm text-blue-900">One latest observation per Shop + Unit + Currency. Currencies and Units are never combined or averaged.</p>
        <form method="GET" class="rounded-lg bg-white p-4 shadow grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div><x-input-label for="unit_id" value="Unit" /><select id="unit_id" name="unit_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">All</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string) request('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach</select></div>
            <div><x-input-label for="currency_id" value="Currency" /><select id="currency_id" name="currency_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">All</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) request('currency_id') === (string) $currency->id)>{{ $currency->code }}</option>@endforeach</select></div>
            <div><x-input-label for="recent_days" value="Observed within days (optional)" /><x-text-input id="recent_days" name="recent_days" type="number" min="1" max="365" class="mt-1 w-full" :value="request('recent_days')" /></div>
            <div class="flex items-end"><x-primary-button>Apply</x-primary-button></div>
        </form>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse($prices as $price)
                <article class="rounded-lg bg-white p-5 shadow space-y-2"><div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold break-words">{{ $price->shop_name }}</h3><span class="font-mono font-semibold">{{ $price->price }} {{ $price->currency_code }}</span></div><p>{{ $price->unit_name }} ({{ $price->unit_code }})</p><p class="text-sm"><span class="text-gray-500">Observed:</span> {{ \Illuminate\Support\Carbon::parse($price->observed_at)->format('Y-m-d H:i:s') }}</p><p class="text-sm"><span class="text-gray-500">Source:</span> {{ \App\Enums\PriceObservationSource::from($price->source)->label() }}</p></article>
            @empty<p>No observations match this comparison.</p>@endforelse
        </div>
        {{ $prices->links() }}
        <a class="inline-block underline text-sm" href="{{ route('products.price-history', $product) }}">View full history</a>
    </div>
</x-app-layout>
