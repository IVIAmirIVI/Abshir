<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ $rule->exists ? 'Version delivery rule' : 'Create delivery rule' }}</h2></x-slot>
    <div class="mx-auto max-w-3xl px-4 py-8">
        @if($errors->any())<div class="mb-4 rounded bg-red-50 p-4"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
        <form class="grid gap-4 rounded bg-white p-5 shadow sm:grid-cols-2" method="POST" action="{{ $rule->exists ? route('delivery-pricing-rules.update', $rule) : route('delivery-pricing-rules.store') }}">
            @csrf
            @if($rule->exists)@method('PUT')@endif
            <label>Origin Area<select name="from_area_id" class="mt-1 block w-full rounded border-gray-300">@foreach($areas as $a)<option value="{{ $a->id }}" @selected(old('from_area_id', $rule->from_area_id) == $a->id)>{{ $a->path() }}</option>@endforeach</select></label>
            <label>Destination Area<select name="to_area_id" class="mt-1 block w-full rounded border-gray-300">@foreach($areas as $a)<option value="{{ $a->id }}" @selected(old('to_area_id', $rule->to_area_id) == $a->id)>{{ $a->path() }}</option>@endforeach</select></label>
            <label>Currency<select name="currency_id" class="mt-1 block w-full rounded border-gray-300">@foreach($currencies as $c)<option value="{{ $c->id }}" @selected(old('currency_id', $rule->currency_id) == $c->id)>{{ $c->code }}</option>@endforeach</select></label>
            <label>Order Pricing Class<select name="order_pricing_class" class="mt-1 block w-full rounded border-gray-300" required>@foreach($pricingClasses as $class)<option value="{{ $class->value }}" @selected(old('order_pricing_class', $rule->order_pricing_class?->value) === $class->value)>{{ $class->label() }}</option>@endforeach</select></label>
            <label>Unit fee<x-text-input name="delivery_unit_fee" type="number" step="0.0001" min="0.0001" class="mt-1 block w-full" :value="old('delivery_unit_fee', $rule->delivery_unit_fee)" /></label>
            <label>Effective from<x-text-input name="effective_from" type="datetime-local" class="mt-1 block w-full" :value="old('effective_from', $rule->effective_from?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i'))" /></label>
            <label>Effective until<x-text-input name="effective_until" type="datetime-local" class="mt-1 block w-full" :value="old('effective_until', $rule->effective_until?->format('Y-m-d\TH:i'))" /></label>
            <textarea name="notes" class="rounded border-gray-300 sm:col-span-2" placeholder="Notes">{{ old('notes', $rule->notes) }}</textarea>
            <x-primary-button>{{ $rule->exists ? 'Create version' : 'Create rule' }}</x-primary-button>
        </form>
    </div>
</x-app-layout>
