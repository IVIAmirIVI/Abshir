<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Record price observation</h2></x-slot>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5 min-w-0">
        <div class="rounded-lg bg-blue-50 p-4 text-sm text-blue-900">This creates immutable historical evidence. <strong>Observed at</strong> is when the price was obtained; it may differ from the entry time.</div>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('product-prices.store') }}" class="rounded-lg bg-white p-5 shadow grid gap-5 sm:grid-cols-2">
            @csrf
            <div><x-input-label for="product_id" value="Product" /><select id="product_id" name="product_id" class="mt-1 block w-full rounded-md border-gray-300" required><option value="">Select Product</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) old('product_id', request('product_id')) === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div><x-input-label for="shop_id" value="Shop" /><select id="shop_id" name="shop_id" class="mt-1 block w-full rounded-md border-gray-300" required><option value="">Select Shop</option>@foreach($shops as $shop)<option value="{{ $shop->id }}" @selected((string) old('shop_id', request('shop_id')) === (string) $shop->id)>{{ $shop->name }}{{ $shop->area ? ' — '.$shop->area->name : '' }}</option>@endforeach</select></div>
            <div><x-input-label for="unit_id" value="Unit" /><select id="unit_id" name="unit_id" class="mt-1 block w-full rounded-md border-gray-300" required><option value="">Select Unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string) old('unit_id') === (string) $unit->id)>{{ $unit->name }} ({{ $unit->code }})</option>@endforeach</select></div>
            <div><x-input-label for="currency_id" value="Currency" /><select id="currency_id" name="currency_id" class="mt-1 block w-full rounded-md border-gray-300" required><option value="">Select Currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) old('currency_id') === (string) $currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></div>
            <div><x-input-label for="price" value="Observed price" /><x-text-input id="price" name="price" type="number" min="0.0001" step="0.0001" class="mt-1 block w-full" :value="old('price')" required /></div>
            <div><x-input-label for="observed_at" value="Observed at" /><x-text-input id="observed_at" name="observed_at" type="datetime-local" class="mt-1 block w-full" :value="old('observed_at', now()->format('Y-m-d\TH:i'))" required /></div>
            <div><x-input-label for="source" value="Source" /><select id="source" name="source" class="mt-1 block w-full rounded-md border-gray-300" required>@foreach($sources as $source)<option value="{{ $source->value }}" @selected(old('source') === $source->value)>{{ $source->label() }}</option>@endforeach</select></div>
            <div class="sm:col-span-2"><x-input-label for="notes" value="Notes (optional)" /><textarea id="notes" name="notes" rows="4" class="mt-1 block w-full rounded-md border-gray-300">{{ old('notes') }}</textarea></div>
            <div class="sm:col-span-2 flex flex-wrap gap-3"><x-primary-button>Record observation</x-primary-button><a class="rounded-md border px-4 py-2 text-sm" href="{{ route('product-prices.index') }}">Cancel</a></div>
        </form>
    </div>
</x-app-layout>
