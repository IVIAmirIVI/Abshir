@props(['items'])
<div class="text-sm space-y-1 border-t pt-2">
    @foreach(['estimate' => false, 'actual' => true] as $kind => $actual)
        <p>{{ __('order_ux.shop_'.$kind) }}:
            @forelse(app(\App\Services\OrderLineTotalService::class)->shop($items, $actual) as $currency => $amount)<x-money :amount="$amount" :currency="$currency" />
            @empty{{ __('common.operational_unavailable') }}@endforelse
        </p>
    @endforeach
</div>
