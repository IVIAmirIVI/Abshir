<section class="rounded bg-white p-5 shadow space-y-3">
    <h3 class="font-semibold">Order entry</h3>
    <div class="grid gap-3 sm:grid-cols-3">
        <p><span class="text-xs uppercase text-gray-500">Source</span><br>{{ $order->source?->label() ?? 'Legacy / not recorded' }}</p>
        <p><span class="text-xs uppercase text-gray-500">Order time</span><br>{{ $order->ordered_at->format('Y-m-d H:i:s') }}</p>
        <p><span class="text-xs uppercase text-gray-500">System entry time</span><br>{{ $order->created_at->format('Y-m-d H:i:s') }}</p>
    </div>
    @if($order->entry_mode === \App\Enums\OrderEntryMode::HistoricalManual)<span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-900">Historical manual entry</span>@elseif(!$order->entry_mode)<span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-sm">Legacy entry</span>@endif
</section>

<section class="space-y-4">
    <div><h3 class="font-semibold">Shop progress and private receipt evidence</h3><p class="text-sm text-gray-600">Progress times are set by the server and cannot be rewritten.</p></div>
    @php($missingDeliveryEvidence = $order->shops->filter(fn ($shop) => !$shop->picked_up_at || $shop->receipts->isEmpty()))
    @if($missingDeliveryEvidence->isEmpty() && $order->shops->isNotEmpty())<p class="rounded bg-green-50 p-3 text-green-800">Delivery evidence is complete for every Shop.</p>@else<p class="rounded bg-amber-50 p-3 text-amber-900">Delivery is blocked until every Shop is marked picked up and has at least one receipt. Missing: {{ $missingDeliveryEvidence->pluck('shop_name_snapshot')->implode(', ') ?: 'add at least one Shop' }}.</p>@endif
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach($order->shops as $orderShop)
            <article class="rounded bg-white p-5 shadow space-y-3 min-w-0">
                <h4 class="font-medium break-words">{{ $orderShop->shop_name_snapshot }}</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                    @foreach(\App\Services\OrderShopProgressService::MARKERS as $marker)
                        <div><span class="text-gray-600">{{ str($marker)->beforeLast('_at')->replace('_', ' ')->title() }}:</span> {{ $orderShop->{$marker}?->format('Y-m-d H:i') ?? 'Pending' }}</div>
                    @endforeach
                </div>
                @can('orders.update')
                    @if(!in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true) && $order->currentInvoice?->status !== \App\Enums\InvoiceStatus::Issued)
                        <form method="POST" action="{{ route('orders.shops.progress', [$order, $orderShop]) }}" class="flex flex-wrap gap-2">@csrf @method('PATCH')
                            <select name="marker" class="rounded border-gray-300">@foreach(\App\Services\OrderShopProgressService::MARKERS as $marker)@if(!$orderShop->{$marker})<option value="{{ $marker }}">Record {{ str($marker)->beforeLast('_at')->replace('_', ' ') }}</option>@endif @endforeach</select>
                            <x-primary-button>Record progress</x-primary-button>
                        </form>
                    @endif
                @endcan
                <div class="space-y-1"><p class="font-medium text-sm">Receipts</p>@forelse($orderShop->receipts as $receipt)<p class="text-sm break-all"><a class="underline" href="{{ route('order-shop-receipts.show', [$order, $orderShop, $receipt]) }}" target="_blank" rel="noopener">{{ $receipt->original_filename }}</a> · {{ $receipt->uploaded_at->format('Y-m-d H:i') }} · {{ $receipt->uploadedBy?->name ?? 'Former user' }}</p>@empty<p class="text-sm text-gray-600">No receipt evidence.</p>@endforelse</div>
                @can('order_shop_receipts.upload')
                    @if($order->operational_status !== \App\Enums\OrderOperationalStatus::Delivered && ($order->operational_status !== \App\Enums\OrderOperationalStatus::Cancelled || $order->cancellation?->classification === \App\Enums\OrderCancellationClassification::AfterPurchase))
                        <form method="POST" enctype="multipart/form-data" action="{{ route('orders.shops.receipts.store', [$order, $orderShop]) }}" class="grid gap-2">@csrf<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required><x-text-input name="note" placeholder="Optional evidence note"/><x-primary-button>Upload receipt</x-primary-button></form>
                    @endif
                @endcan
            </article>
        @endforeach
    </div>
</section>

<section class="rounded bg-white p-5 shadow space-y-4">
    <h3 class="font-semibold">Cancellation</h3>
    @if($order->cancellation)
        <p><strong>{{ $order->cancellation->classification->label() }}</strong> · {{ $order->cancellation->reason->label() }} · {{ $order->cancellation->cancelled_at->format('Y-m-d H:i:s') }} · {{ $order->cancellation->cancelledBy?->name ?? 'Former user' }}</p>
        @if($order->cancellation->requiresFinancialResolution())<p class="rounded bg-amber-50 p-3 text-amber-900">Financial resolution is required. Phase 8.5 records the exception but does not post or reverse money.</p>@endif
        @if($order->cancellation->notes)<p class="whitespace-pre-wrap break-words">{{ $order->cancellation->notes }}</p>@endif
    @elseif(!in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
        @can('orders.cancel')<form method="POST" action="{{ route('orders.cancel', $order) }}" class="grid gap-3 sm:grid-cols-2">@csrf<select name="classification" class="rounded border-gray-300">@foreach(\App\Enums\OrderCancellationClassification::cases() as $classification)<option value="{{ $classification->value }}">{{ $classification->label() }}</option>@endforeach</select><select name="reason" class="rounded border-gray-300">@foreach(\App\Enums\OrderCancellationReason::cases() as $reason)<option value="{{ $reason->value }}">{{ $reason->label() }}</option>@endforeach</select><textarea name="notes" class="rounded border-gray-300 sm:col-span-2" placeholder="Optional cancellation notes"></textarea><x-primary-button>Cancel Order</x-primary-button></form>@endcan
    @endif
</section>

@if($order->operational_status === \App\Enums\OrderOperationalStatus::Delivered)
<section class="rounded bg-white p-5 shadow space-y-4">
    <h3 class="font-semibold">Captain ratings</h3>
    @php($ratedCaptainIds = $order->captainRatings->pluck('captain_id'))
    @foreach($order->captainRatings as $rating)<p class="text-sm">{{ $rating->captain->user->name }} · {{ $rating->rating }}/5 · {{ $rating->source->label() }} · {{ $rating->rated_at->format('Y-m-d H:i') }}</p>@endforeach
    @can('captain_ratings.record')
        @foreach($order->captainAssignments->whereNull('removed_at')->unique('captain_id') as $assignment)
            @unless($ratedCaptainIds->contains($assignment->captain_id))<form method="POST" action="{{ route('orders.captain-ratings.store', $order) }}" class="flex flex-wrap items-end gap-2">@csrf<input type="hidden" name="captain_id" value="{{ $assignment->captain_id }}"><div><x-input-label value="Rate {{ $assignment->captain->user->name }}"/><select name="rating" class="rounded border-gray-300">@for($score = 5; $score >= 1; $score--)<option value="{{ $score }}">{{ $score }}/5</option>@endfor</select></div><x-primary-button>Save rating</x-primary-button></form>@endunless
        @endforeach
    @endcan
</section>
@endif
