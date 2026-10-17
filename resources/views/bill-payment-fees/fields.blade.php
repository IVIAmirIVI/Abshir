@php($mode = $rule->fee_mode ?? 'FIXED')
@php($tiers = $rule->fee_tiers ?? \App\Services\BillPaymentFeeRuleService::sypTiers())
<div x-data="{ mode: @js($mode) }" class="w-full min-w-0 space-y-3">
    <label class="block text-sm">{{ __('bill_fees.mode') }}
        <select name="fee_mode" x-model="mode" class="max-w-full rounded border-gray-300">
            <option value="FIXED">{{ __('bill_fees.fixed') }}</option>
            @if($supportsTiers)<option value="TIERED">{{ __('bill_fees.tiered') }}</option>@endif
        </select>
    </label>
    <div x-show="mode === 'FIXED'">
        <label class="block text-sm">{{ __('admin.ui.fixed_fee') }}<x-text-input class="mt-1 block w-full" dir="ltr" name="fixed_fee" :value="$rule->fixed_fee ?? ''" :placeholder="__('admin.ui.fixed_fee')" x-bind:required="mode === 'FIXED'" x-bind:disabled="mode !== 'FIXED'" /></label>
    </div>
    <fieldset x-show="mode === 'TIERED'" x-cloak x-bind:disabled="mode !== 'TIERED'" class="min-w-0 space-y-3">
        @foreach($tiers as $index => $tier)
            <div class="grid gap-2 sm:grid-cols-4">
                <label class="min-w-0 text-sm">{{ __('bill_fees.from') }}<x-text-input class="w-full" dir="ltr" name="fee_tiers[{{ $index }}][from]" :value="$tier['from']" required /></label>
                <label class="min-w-0 text-sm">{{ __('bill_fees.until') }}<x-text-input class="w-full" dir="ltr" name="fee_tiers[{{ $index }}][until]" :value="$tier['until'] ?? ''" /></label>
                <select aria-label="{{ __('bill_fees.mode') }}" name="fee_tiers[{{ $index }}][type]" class="max-w-full rounded border-gray-300">
                    <option value="FIXED" @selected($tier['type'] === 'FIXED')>{{ __('bill_fees.fixed') }}</option>
                    <option value="PERCENTAGE" @selected($tier['type'] === 'PERCENTAGE')>{{ __('bill_fees.percentage') }}</option>
                </select>
                <label class="min-w-0 text-sm">{{ __('bill_fees.value') }}<x-text-input class="w-full" dir="ltr" name="fee_tiers[{{ $index }}][value]" :value="$tier['value']" required /></label>
            </div>
        @endforeach
    </fieldset>
</div>
