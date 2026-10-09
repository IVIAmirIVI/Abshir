<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ $rule->exists ? __('admin.ui.version_delivery_rule') : __('admin.ui.create_delivery_rule') }}</h2></x-slot>
    <x-management.page>
        @if($errors->any())<div role="alert" class="mb-4 rounded bg-red-50 p-4"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
        <form class="grid gap-4 rounded bg-white p-5 shadow sm:grid-cols-2" method="POST" action="{{ $rule->exists ? route('delivery-pricing-rules.update', $rule) : route('delivery-pricing-rules.store') }}">
            @csrf
            @if($rule->exists)@method('PUT')@endif
            <label>{{ __('admin.ui.origin_area') }}<select name="from_area_id" class="min-w-0 max-w-full mt-1 block w-full rounded border-gray-300">@foreach($areas as $a)<option value="{{ $a->id }}" @selected(old('from_area_id', $rule->from_area_id) == $a->id)>{{ $a->path() }}</option>@endforeach</select></label>
            <label>{{ __('admin.ui.destination_area') }}<select name="to_area_id" class="min-w-0 max-w-full mt-1 block w-full rounded border-gray-300">@foreach($areas as $a)<option value="{{ $a->id }}" @selected(old('to_area_id', $rule->to_area_id) == $a->id)>{{ $a->path() }}</option>@endforeach</select></label>
            <label>{{ __('admin.ui.currency') }}<select name="currency_id" class="min-w-0 max-w-full mt-1 block w-full rounded border-gray-300">@foreach($currencies as $c)<option value="{{ $c->id }}" @selected(old('currency_id', $rule->currency_id) == $c->id)>{{ $c->code }}</option>@endforeach</select></label>
            <label>{{ __('admin.ui.order_pricing_class') }}<select name="order_pricing_class" class="min-w-0 max-w-full mt-1 block w-full rounded border-gray-300" required>@foreach($pricingClasses as $class)<option value="{{ $class->value }}" @selected(old('order_pricing_class', $rule->order_pricing_class?->value) === $class->value)>{{ \App\Support\UiLabels::label('orders.pricing_classes', $class) }}</option>@endforeach</select></label>
            <label>{{ __('admin.ui.unit_fee') }}<x-text-input class="min-w-0 max-w-full mt-1 block w-full"  dir="ltr" name="delivery_unit_fee" type="number" step="0.0001" min="0.0001"  :value="old('delivery_unit_fee', $rule->delivery_unit_fee)" /></label>
            <label>{{ __('admin.ui.effective_from') }}<x-text-input class="min-w-0 max-w-full mt-1 block w-full"  dir="ltr" name="effective_from" type="datetime-local"  :value="old('effective_from', $rule->effective_from?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i'))" /></label>
            <label>{{ __('admin.ui.effective_until') }}<x-text-input class="min-w-0 max-w-full mt-1 block w-full"  dir="ltr" name="effective_until" type="datetime-local"  :value="old('effective_until', $rule->effective_until?->format('Y-m-d\TH:i'))" /></label>
            <textarea name="notes" class="rounded border-gray-300 sm:col-span-2" placeholder="{{ __('admin.ui.notes') }}" aria-label="{{ __('admin.ui.notes') }}">{{ old('notes', $rule->notes) }}</textarea>
            <x-primary-button>{{ $rule->exists ? __('admin.ui.create_version') : __('admin.ui.create_rule') }}</x-primary-button>
        </form>
    </x-management.page>
</x-app-layout>
