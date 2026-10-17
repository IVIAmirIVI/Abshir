<ul class="text-sm text-gray-600 space-y-1">
    @foreach($tiers as $tier)
        <li>
            {{ __('bill_fees.from_inclusive') }} <x-ltr>{{ $tier['from'] }}</x-ltr>
            @if($tier['until'] !== null){{ __('bill_fees.until_exclusive') }} <x-ltr>{{ $tier['until'] }}</x-ltr>@else{{ __('bill_fees.and_above') }}@endif:
            <x-ltr>{{ $tier['value'] }}{{ $tier['type'] === 'PERCENTAGE' ? '%' : ' SYP' }}</x-ltr>
            {{ __($tier['type'] === 'PERCENTAGE' ? 'bill_fees.entire_principal' : 'bill_fees.fixed') }}
        </li>
    @endforeach
    <li>{{ __('bill_fees.positive_only') }}</li>
    <li>{{ __('bill_fees.rounding') }}</li>
</ul>
