<div class="min-w-0 space-y-2 sm:col-span-2">
    @if($mode === 'PRICE')
        <label>{{ __('orders.ui_actual_price') }}<input name="actual_unit_price" wire:model.live.debounce.200ms="price" value="{{ $price }}" type="text" inputmode="decimal" dir="ltr" class="w-full rounded border-gray-300" /></label>
        <select aria-label="{{ __('common.terms.currency') }}" name="actual_currency_id" wire:model.live="currencyId" class="w-full rounded border-gray-300">@foreach(\App\Models\Currency::where('is_active', true)->orderBy('code')->get() as $option)<option value="{{ $option->id }}" @selected((string) $option->id === $currencyId)>{{ $option->code }}</option>@endforeach</select>
    @else
        <label>{{ __('orders.ui_quantity') }}<input name="quantity" wire:model.live.debounce.200ms="quantity" value="{{ $quantity }}" type="text" inputmode="decimal" dir="ltr" class="w-full rounded border-gray-300" /></label>
    @endif
    <p class="text-sm" aria-live="polite" x-show="typeof changeType === 'undefined' || changeType === 'QUANTITY_CHANGE'">{{ __('order_ux.preview') }}:
        {{ __($mode === 'PRICE' || $item->actual_unit_price !== null ? 'order_ux.line_actual' : 'order_ux.line_estimate') }}
        @if($total !== null)<x-money :amount="$total" :currency="$currency->code" />@else{{ __('common.operational_unavailable') }}@endif
    </p>
</div>
