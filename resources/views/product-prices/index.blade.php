<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 break-words">{{ $title }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap justify-between gap-3">
            <p class="text-sm text-gray-600">Append-only evidence ordered by observed time, then record ID.</p>
            @can('product_prices.create')<a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('product-prices.create', request()->only(['product_id', 'shop_id'])) }}">Add observation</a>@endcan
        </div>
        <form method="GET" class="rounded-lg bg-white p-4 shadow grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @if($lockedProduct)<input type="hidden" name="product_id" value="{{ $lockedProduct->id }}">@else<div><x-input-label for="product_id" value="Product" /><select id="product_id" name="product_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">All</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>@endif
            @if($lockedShop)<input type="hidden" name="shop_id" value="{{ $lockedShop->id }}">@else<div><x-input-label for="shop_id" value="Shop" /><select id="shop_id" name="shop_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">All</option>@foreach($shops as $shop)<option value="{{ $shop->id }}" @selected((string) request('shop_id') === (string) $shop->id)>{{ $shop->name }}</option>@endforeach</select></div>@endif
            <div><x-input-label for="unit_id" value="Unit" /><select id="unit_id" name="unit_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">All</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string) request('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach</select></div>
            <div><x-input-label for="currency_id" value="Currency" /><select id="currency_id" name="currency_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">All</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) request('currency_id') === (string) $currency->id)>{{ $currency->code }}</option>@endforeach</select></div>
            <div class="flex items-end"><x-primary-button>Filter</x-primary-button></div>
        </form>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse($observations as $observation)
                <article class="rounded-lg bg-white p-5 shadow space-y-3 min-w-0">
                    <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold break-words">{{ $observation->product->name }}</h3><span class="font-mono font-semibold">{{ $observation->price }} {{ $observation->currency->code }}</span></div>
                    <p class="break-words"><a class="underline" href="{{ route('shops.show', $observation->shop) }}">{{ $observation->shop->name }}</a> · {{ $observation->unit->name }}</p>
                    <dl class="grid gap-2 text-sm"><div><dt class="text-xs uppercase text-gray-500">Observed at</dt><dd>{{ $observation->observed_at->format('Y-m-d H:i:s') }}</dd></div><div><dt class="text-xs uppercase text-gray-500">Entered at</dt><dd>{{ $observation->created_at->format('Y-m-d H:i:s') }}</dd></div><div><dt class="text-xs uppercase text-gray-500">Source</dt><dd>{{ $observation->source->label() }}</dd></div><div><dt class="text-xs uppercase text-gray-500">Recorded by</dt><dd>{{ $observation->recordedBy?->name ?? 'Unknown/deleted user' }}</dd></div></dl>
                    @if($observation->notes)<p class="text-sm whitespace-pre-wrap break-words">{{ $observation->notes }}</p>@endif
                </article>
            @empty<p>No price observations match these filters.</p>@endforelse
        </div>
        {{ $observations->links() }}
        @if($lockedProduct)@can('product_prices.reports')<a class="inline-block underline text-sm" href="{{ route('products.price-comparison', $lockedProduct) }}">Open latest price comparison</a>@endcan @endif
    </div>
</x-app-layout>
