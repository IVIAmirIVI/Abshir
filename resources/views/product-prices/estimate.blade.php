<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Unknown-Shop estimate preview</h2></x-slot>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5 min-w-0">
        <p class="rounded-lg bg-blue-50 p-4 text-sm text-blue-900">Uses the latest observation for each accessible Shop, then selects the lowest of those latest prices. Window: {{ $windowDays }} days.</p>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="GET" class="rounded-lg bg-white p-5 shadow grid gap-4 sm:grid-cols-2">
            <input type="hidden" name="preview" value="1">
            <div><x-input-label for="product_id" value="Product" /><select id="product_id" name="product_id" class="mt-1 w-full rounded-md border-gray-300" required><option value="">Select</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div><x-input-label for="unit_id" value="Unit" /><select id="unit_id" name="unit_id" class="mt-1 w-full rounded-md border-gray-300" required><option value="">Select</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string) request('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach</select></div>
            <div><x-input-label for="currency_id" value="Currency" /><select id="currency_id" name="currency_id" class="mt-1 w-full rounded-md border-gray-300" required><option value="">Select</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) request('currency_id') === (string) $currency->id)>{{ $currency->code }}</option>@endforeach</select></div>
            <div><x-input-label for="reference_at" value="Reference time (optional)" /><x-text-input id="reference_at" name="reference_at" type="datetime-local" class="mt-1 w-full" :value="request('reference_at')" /></div>
            <div class="sm:col-span-2"><x-primary-button>Preview estimate</x-primary-button></div>
        </form>
        @if($result)
            @if($result->available())
                <section class="rounded-lg bg-green-50 p-5 text-green-950 space-y-3"><h3 class="font-semibold text-lg">Suggested price: {{ $result->price }}</h3><dl class="grid gap-3 sm:grid-cols-2"><div><dt class="text-xs uppercase">Source Shop</dt><dd>{{ $result->sourceShop->name }}</dd></div><div><dt class="text-xs uppercase">Observed at</dt><dd>{{ $result->sourceObservedAt->format('Y-m-d H:i:s') }}</dd></div><div><dt class="text-xs uppercase">Window</dt><dd>{{ $result->estimationWindowDays }} days</dd></div><div><dt class="text-xs uppercase">Method</dt><dd class="break-all">{{ $result->estimationMethod }}</dd></div><div><dt class="text-xs uppercase">Observation ID</dt><dd>{{ $result->sourceObservationId }}</dd></div></dl></section>
            @else
                <section class="rounded-lg bg-amber-50 p-5 text-amber-950"><h3 class="font-semibold">No estimate available</h3><p class="mt-2 text-sm">No valid observation exists for the selected Product, Unit, and Currency inside the {{ $result->estimationWindowDays }}-day window.</p></section>
            @endif
        @endif
    </div>
</x-app-layout>
