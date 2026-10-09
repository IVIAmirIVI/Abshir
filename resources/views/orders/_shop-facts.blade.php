<div class="space-y-3 text-sm">
    <p>{{ $orderShop->originArea?->path() }} · {{ $orderShop->shop_address_snapshot }}</p>
    <div class="grid grid-cols-2 gap-2">@foreach(\App\Services\OrderShopProgressService::MARKERS as $marker)<p>{{ \App\Support\UiLabels::label('orders.progress_markers', $marker) }}: @if($orderShop->{$marker})<x-ltr>{{ $orderShop->{$marker}->format('Y-m-d H:i') }}</x-ltr>@else{{ __('orders.workflow.not_recorded') }}@endif</p>@endforeach</div>
    @if($purchase = $orderShop->purchaseConfirmation)<p>{{ __('orders.ui_verified_purchase') }} <x-money :amount="$purchase->amount" /> <x-ltr>{{ $purchase->currency?->code }}</x-ltr> ·
        @if($purchase->purchase_mode === \App\Services\SharedPurchaseBoundary::MODE)
            {{ $purchase->allocations->map(fn ($share) => $share->orderCaptain?->captain?->user?->name)->implode('، ') }}
        @else {{ $order->captainAssignments->firstWhere('id', $purchase->order_captain_id)?->captain?->user?->name }} @endif
        </p>@can('order_shop_purchases.view')<a class="block underline" href="{{ route('orders.shops.purchases.evidence', [$order, $orderShop, $purchase]) }}" target="_blank" rel="noopener">{{ __('orders.ui_purchase_evidence') }}</a>@endcan @endif
    @can('order_shop_receipts.view')@foreach($orderShop->receipts as $receipt)<a class="block break-all underline" href="{{ route('order-shop-receipts.show', [$order, $orderShop, $receipt]) }}" target="_blank" rel="noopener"><x-ltr>{{ $receipt->original_filename }}</x-ltr></a>@endforeach @endcan
</div>
