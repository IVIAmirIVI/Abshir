@props(['item'])
@php($totals = app(\App\Services\OrderLineTotalService::class))
<div class="text-sm space-y-1">
    @foreach(['estimate' => [$item->estimated_unit_price, $item->estimatedCurrency], 'actual' => [$item->actual_unit_price, $item->actualCurrency]] as $kind => [$price, $currency])
        <p>{{ __('order_ux.line_'.$kind) }}:
            @if(($amount = $totals->calculate($item->current_quantity, $price, $currency)) !== null)<x-money :amount="$amount" :currency="$currency->code" />
            @else{{ __('common.operational_unavailable') }}@endif
        </p>
    @endforeach
</div>
