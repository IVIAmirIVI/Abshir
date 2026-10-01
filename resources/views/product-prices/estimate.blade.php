<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('pricing.unknown_shop_estimate_preview') }}</h2></x-slot>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5 min-w-0">
        <p class="rounded-lg bg-blue-50 p-4 text-sm text-blue-900">{{ __('pricing.estimate_help', ['days' => $windowDays]) }}</p>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="GET" class="rounded-lg bg-white p-5 shadow grid gap-4 sm:grid-cols-2">
            <input type="hidden" name="preview" value="1">
            <div><x-input-label for="product_id" :value="__('common.terms.product')" /><select id="product_id" name="product_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300" required><option value="">{{ __('pricing.select') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div><x-input-label for="unit_id" :value="__('common.terms.unit')" /><select id="unit_id" name="unit_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300" required><option value="">{{ __('pricing.select') }}</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string) request('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach</select></div>
            <div><x-input-label for="currency_id" :value="__('common.terms.currency')" /><select id="currency_id" name="currency_id" class="min-w-0 max-w-full mt-1 w-full rounded-md border-gray-300" required><option value="">{{ __('pricing.select') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) request('currency_id') === (string) $currency->id)>{{ "\u{2066}".$currency->code."\u{2069}" }}</option>@endforeach</select></div>
            <div><x-input-label for="reference_at" :value="__('pricing.reference_time_optional')" /><x-text-input id="reference_at" name="reference_at" type="datetime-local" class="mt-1 w-full" :value="request('reference_at')" dir="ltr" /></div>
            <div class="sm:col-span-2"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('pricing.preview_estimate') }}</x-primary-button></div>
        </form>
        @if($result)
            @if($result->available())
                <section class="rounded-lg bg-green-50 p-5 text-green-950 space-y-3"><h3 class="font-semibold text-lg">{{ __('pricing.suggested_price') }} <x-money :amount="$result->price" /></h3><dl class="grid gap-3 sm:grid-cols-2"><div><dt class="text-xs uppercase">{{ __('pricing.source_shop') }}</dt><dd>{{ $result->sourceShop->name }}</dd></div><div><dt class="text-xs uppercase">{{ __('pricing.observed_at') }}</dt><dd><x-ltr>{{ $result->sourceObservedAt->format('Y-m-d H:i:s') }}</x-ltr></dd></div><div><dt class="text-xs uppercase">{{ __('pricing.window') }}</dt><dd>{{ $result->estimationWindowDays }} {{ __('pricing.days') }}</dd></div><div><dt class="text-xs uppercase">{{ __('pricing.method') }}</dt><dd class="break-all">{{ \App\Support\UiLabels::label('orders.estimate_methods', $result->estimationMethod) }}</dd></div><div><dt class="text-xs uppercase">{{ __('pricing.observation_id') }}</dt><dd><x-ltr>{{ $result->sourceObservationId }}</x-ltr></dd></div></dl></section>
            @else
                <section class="rounded-lg bg-amber-50 p-5 text-amber-950"><h3 class="font-semibold">{{ __('pricing.no_estimate_available') }}</h3><p class="mt-2 text-sm">{{ __('pricing.unavailable_help', ['days' => $result->estimationWindowDays]) }}</p></section>
            @endif
        @endif
    </div>
</x-app-layout>
