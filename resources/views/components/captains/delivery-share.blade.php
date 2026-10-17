@props(['allocation'])
@if($allocation->special_delivery_components !== null)
    <x-orders.special-delivery-breakdown :allocation="$allocation" />
@elseif($allocation->base_captain_share_percent_snapshot !== null)
    <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-gray-600">{{ __('captains.fuel_base') }}</dt><dd><x-money :amount="$allocation->base_captain_share_percent_snapshot" />%</dd></div>
        <div><dt class="text-gray-600">{{ __('captains.fuel_applied') }}</dt><dd><x-money :amount="$allocation->fuel_allowance_percent_snapshot" />% · <x-money :amount="$allocation->fuel_allowance_amount" /></dd></div>
        <div><dt class="text-gray-600">{{ __('captains.fuel_effective') }}</dt><dd><x-money :amount="$allocation->captain_share_percent_snapshot" />%</dd></div>
        <div><dt class="text-gray-600">{{ __('captains.fuel_company') }}</dt><dd><x-money :amount="(string) \Brick\Math\BigDecimal::of(100)->minus($allocation->captain_share_percent_snapshot)->toScale(4)" />%</dd></div>
    </dl>
@endif
