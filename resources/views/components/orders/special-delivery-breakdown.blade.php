@props(['allocation'])
@php($details = $allocation->special_delivery_components)
<dl class="mt-2 space-y-1 text-sm">
    @foreach($details['parts'] as $part)<div><dt>{{ $part['product_name'] ?? __('special.product') }} · {{ __('special.modes.'.$part['mode']) }} · <x-ltr>#{{ $part['policy_id'] }}</x-ltr></dt><dd><x-money :amount="$part['base_fee']" />@if($part['mode'] !== 'DEFAULT') · {{ __('special.captain_fixed') }} <x-money :amount="$part['custom_captain']" /> · {{ __('special.company_fixed') }} <x-money :amount="(string) \Brick\Math\BigDecimal::of($part['base_fee'])->minus($part['custom_captain'])" />@endif</dd></div>@endforeach
    <div><dt>{{ __('special.extra') }}</dt><dd><x-money :amount="$details['extra_fee']" /></dd></div>
    <div><dt>{{ __('special.standard') }}</dt><dd><x-money :amount="$allocation->captain_share_percent_snapshot" />%</dd></div>
    <div><dt>{{ __('captains.fuel_base') }} / {{ __('captains.fuel_applied') }}</dt><dd><x-money :amount="$allocation->base_captain_share_percent_snapshot" />% + <x-money :amount="$allocation->fuel_allowance_percent_snapshot" />%</dd></div>
</dl>
