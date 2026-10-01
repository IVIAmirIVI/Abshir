<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 break-words">{{ __('pricing.shop_price_comparison') }} {{ $product->name }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5 min-w-0">
        <p class="rounded-lg bg-blue-50 p-4 text-sm text-blue-900">{{ __('pricing.one_latest_observation_per_shop_unit_currency_currencies_and_units_are_never_combined_or_averaged') }}</p>
        <form method="GET" class="rounded-lg bg-white p-4 shadow grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div><x-input-label for="unit_id" :value="__('common.terms.unit')" /><select id="unit_id" name="unit_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300"><option value="">{{ __('catalog.all') }}</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string) request('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach</select></div>
            <div><x-input-label for="currency_id" :value="__('common.terms.currency')" /><select id="currency_id" name="currency_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300"><option value="">{{ __('catalog.all') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) request('currency_id') === (string) $currency->id)>{{ "\u{2066}".$currency->code."\u{2069}" }}</option>@endforeach</select></div>
            <div><x-input-label for="recent_days" :value="__('pricing.observed_within_days_optional')" /><x-text-input id="recent_days" name="recent_days" type="number" min="1" max="365" class="mt-1 w-full" :value="request('recent_days')" dir="ltr" /></div>
            <div class="flex items-end"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('pricing.apply') }}</x-primary-button></div>
        </form>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse($prices as $price)
                <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-2"><div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold break-words">{{ $price->shop_name }}</h3><span class="font-mono font-semibold"><x-money :amount="$price->price" :currency="$price->currency_code" /></span></div><p>{{ $price->unit_name }} (<x-ltr>{{ $price->unit_code }}</x-ltr>)</p><p class="text-sm"><span class="text-gray-500">{{ __('pricing.observed') }}</span> <x-ltr>{{ \Illuminate\Support\Carbon::parse($price->observed_at)->format('Y-m-d H:i:s') }}</x-ltr></p><p class="text-sm"><span class="text-gray-500">{{ __('pricing.source') }}</span> {{ \App\Support\UiLabels::label('pricing.sources', $price->source) }}</p></article>
            @empty<p>{{ __('pricing.no_observations_match_this_comparison') }}</p>@endforelse
        </div>
        {{ $prices->links() }}
        <a class="inline-block underline text-sm" href="{{ route('products.price-history', $product) }}">{{ __('pricing.view_full_history') }}</a>
    </div>
</x-app-layout>
