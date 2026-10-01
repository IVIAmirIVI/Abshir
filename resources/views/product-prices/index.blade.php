<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 break-words">{{ $title }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap justify-between gap-3">
            <p class="text-sm text-gray-600 break-words">{{ __('pricing.append_only_evidence_ordered_by_observed_time_then_record_id') }}</p>
            @can('product_prices.create')<a class="rounded-md bg-brand-primary px-4 py-2 text-white" href="{{ route('product-prices.create', request()->only(['product_id', 'shop_id'])) }}">{{ __('pricing.add_observation') }}</a>@endcan
        </div>
        <form method="GET" class="rounded-lg bg-white p-4 shadow grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @if($lockedProduct)<input type="hidden" name="product_id" value="{{ $lockedProduct->id }}">@else<div><x-input-label for="product_id" :value="__('common.terms.product')" /><select id="product_id" name="product_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300"><option value="">{{ __('catalog.all') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>@endif
            @if($lockedShop)<input type="hidden" name="shop_id" value="{{ $lockedShop->id }}">@else<div><x-input-label for="shop_id" :value="__('common.terms.shop')" /><select id="shop_id" name="shop_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300"><option value="">{{ __('catalog.all') }}</option>@foreach($shops as $shop)<option value="{{ $shop->id }}" @selected((string) request('shop_id') === (string) $shop->id)>{{ $shop->name }}</option>@endforeach</select></div>@endif
            <div><x-input-label for="unit_id" :value="__('common.terms.unit')" /><select id="unit_id" name="unit_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300"><option value="">{{ __('catalog.all') }}</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string) request('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach</select></div>
            <div><x-input-label for="currency_id" :value="__('common.terms.currency')" /><select id="currency_id" name="currency_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300"><option value="">{{ __('catalog.all') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) request('currency_id') === (string) $currency->id)>{{ "\u{2066}".$currency->code."\u{2069}" }}</option>@endforeach</select></div>
            <div class="flex items-end"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('common.actions.filter') }}</x-primary-button></div>
        </form>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse($observations as $observation)
                <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3 min-w-0">
                    <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold break-words">{{ $observation->product->name }}</h3><span class="font-mono font-semibold"><x-money :amount="$observation->price" :currency="$observation->currency->code" /></span></div>
                    <p class="break-words"><a class="underline" href="{{ route('shops.show', $observation->shop) }}">{{ $observation->shop->name }}</a> · {{ $observation->unit->name }}</p>
                    <dl class="grid gap-2 text-sm"><div><dt class="text-xs uppercase text-gray-500">{{ __('pricing.observed_at') }}</dt><dd><x-ltr>{{ $observation->observed_at->format('Y-m-d H:i:s') }}</x-ltr></dd></div><div><dt class="text-xs uppercase text-gray-500">{{ __('pricing.entered_at') }}</dt><dd><x-ltr>{{ $observation->created_at->format('Y-m-d H:i:s') }}</x-ltr></dd></div><div><dt class="text-xs uppercase text-gray-500">{{ __('pricing.source') }}</dt><dd>{{ \App\Support\UiLabels::label('pricing.sources', $observation->source) }}</dd></div><div><dt class="text-xs uppercase text-gray-500">{{ __('pricing.recorded_by') }}</dt><dd>{{ $observation->recordedBy?->name ?? __('pricing.unknown_deleted_user') }}</dd></div></dl>
                    @if($observation->notes)<p class="text-sm whitespace-pre-wrap break-words">{{ $observation->notes }}</p>@endif
                </article>
            @empty<p>{{ __('pricing.no_price_observations_match_these_filters') }}</p>@endforelse
        </div>
        {{ $observations->links() }}
        @if($lockedProduct)@can('product_prices.reports')<a class="inline-block underline text-sm" href="{{ route('products.price-comparison', $lockedProduct) }}">{{ __('pricing.open_latest_price_comparison') }}</a>@endcan @endif
    </div>
</x-app-layout>
