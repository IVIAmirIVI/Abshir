<label class="block text-sm">{{ __('commissions.method') }}
    <select name="method" required class="mt-1 w-full">
        @foreach(['PERCENTAGE', 'FIXED'] as $method)
            <option value="{{ $method }}" @selected(old('method', $agreement?->method) === $method)>{{ __('commissions.methods.'.$method) }}</option>
        @endforeach
    </select>
</label>
<label class="block text-sm">{{ __('commissions.value') }}
    <x-text-input name="commission_value" required inputmode="decimal" dir="ltr" class="mt-1 block w-full" value="{{ old('commission_value', $agreement?->commission_value) }}" />
</label>
@if($agreement ? $agreement->product_group_id : true)
    <label class="block text-sm" @if(!$agreement) x-show="target === 'group'" x-cloak @endif>{{ __('commissions.priority') }}
        <input name="group_priority" inputmode="numeric" dir="ltr" class="input mt-1 block w-full" value="{{ old('group_priority', $agreement?->group_priority) }}"
            @if(!$agreement) x-bind:disabled="target !== 'group'" x-bind:required="target === 'group'" @else required @endif>
    </label>
@endif
<div class="space-y-2 sm:col-span-2" data-end-presets data-start-required="{{ __('commissions.preset_start_required') }}">
    <p class="text-sm font-semibold">{{ __('commissions.validity_shortcuts') }}</p>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        @foreach(['day', 'week', 'month', 'halfYear', 'year', 'none'] as $duration)
            <x-secondary-button data-end-duration="{{ $duration }}" class="w-full">{{ __('commissions.duration_'.$duration) }}</x-secondary-button>
        @endforeach
    </div>
    <p class="text-xs text-gray-600">{{ __('commissions.validity_shortcuts_help') }}</p>
    <p class="text-sm text-red-700" role="status" data-preset-status></p>
</div>
<label class="block text-sm">{{ __('commissions.effective_from') }}
    <x-text-input type="text" data-management-datetime placeholder="YYYY-MM-DDTHH:mm" autocomplete="off" lang="en" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(:[0-9]{2}([.][0-9]{1,6})?)?(Z|[+\-][0-9]{2}:[0-9]{2})?" name="effective_from" required dir="ltr" class="mt-1 block w-full" value="{{ old('effective_from', $agreement?->effective_from?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d\TH:i:s.u')) }}" />
</label>
<label class="block text-sm">{{ __('commissions.effective_until') }}
    <x-text-input type="text" data-management-datetime placeholder="YYYY-MM-DDTHH:mm" autocomplete="off" lang="en" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(:[0-9]{2}([.][0-9]{1,6})?)?(Z|[+\-][0-9]{2}:[0-9]{2})?" name="effective_until" dir="ltr" class="mt-1 block w-full" value="{{ old('effective_until', $agreement?->effective_until?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d\TH:i:s.u')) }}" />
</label>
<p class="text-xs sm:col-span-2">{{ __($accountingInstalled ? 'commissions.calculation_policy' : 'commissions.policy_pending') }} {{ __('commissions.priority_help') }}</p>
