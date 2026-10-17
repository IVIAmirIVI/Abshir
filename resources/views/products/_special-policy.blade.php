@php($policy = $product->exists ? $product->deliveryPolicy : null)
<fieldset class="space-y-3 rounded-lg border border-gray-200 p-4" x-data="{ special: @js((bool) old('is_special', $product->is_special)), mode: @js(old('special_policy.allocation_mode', $policy?->allocation_mode ?? 'DEFAULT')) }">
    <legend class="px-2 font-semibold">{{ __('special.title') }} — {{ __('special.policy') }}</legend>
    <input type="hidden" name="is_special" value="0">
    <label class="flex items-center gap-2"><input type="checkbox" name="is_special" value="1" x-model="special" @checked(old('is_special', $product->is_special))>{{ __('special.product') }}</label>
    <div x-show="special" class="grid min-w-0 gap-4 sm:grid-cols-2">
        <label>{{ __('common.terms.currency') }}<select name="special_policy[currency_id]" class="mt-1 w-full rounded-lg border-gray-300"><option value="">—</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) old('special_policy.currency_id', $policy?->currency_id) === (string) $currency->id)>{{ $currency->code }}</option>@endforeach</select></label>
        @foreach(['delivery_fee' => 'fee', 'captain_percentage' => 'captain_percentage', 'captain_fixed' => 'captain_fixed', 'company_fixed' => 'company_fixed'] as $field => $label)
            <label @if($field === 'captain_percentage') x-show="mode === 'PERCENTAGE'" @elseif($field !== 'delivery_fee') x-show="mode === 'FIXED'" @endif>{{ __('special.'.$label) }}<x-text-input name="special_policy[{{ $field }}]" type="text" inputmode="decimal" dir="ltr" class="mt-1 w-full" value="{{ old('special_policy.'.$field, $policy?->$field) }}" /></label>
        @endforeach
        <label>{{ __('special.mode') }}<select name="special_policy[allocation_mode]" x-model="mode" class="mt-1 w-full rounded-lg border-gray-300">@foreach(__('special.modes') as $mode => $label)<option value="{{ $mode }}" @selected(old('special_policy.allocation_mode', $policy?->allocation_mode ?? 'DEFAULT') === $mode)>{{ $label }}</option>@endforeach</select></label>
    </div>
    <p class="text-sm text-gray-600">{{ __('special.help') }}</p>
</fieldset>
