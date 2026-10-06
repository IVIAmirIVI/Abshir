<section class="rounded bg-white p-5 shadow space-y-3">
    <h3 class="font-semibold">{{ __('orders.ui_entry') }}</h3>
    <div class="grid gap-3 sm:grid-cols-3">
        <p><span class="text-xs uppercase text-gray-500">{{ __('orders.ui_source_heading') }}</span><br>{{ ($order->source ? \App\Support\UiLabels::label('orders.sources', $order->source) : null) ?? __('orders.ui_legacy_source') }}</p>
        <p><span class="text-xs uppercase text-gray-500">{{ __('orders.ui_order_time') }}</span><br><x-ltr>{{ $order->ordered_at->format('Y-m-d H:i:s') }}</x-ltr></p>
        <p><span class="text-xs uppercase text-gray-500">{{ __('orders.ui_system_time') }}</span><br><x-ltr>{{ $order->created_at->format('Y-m-d H:i:s') }}</x-ltr></p>
    </div>
    @if($order->entry_mode === \App\Enums\OrderEntryMode::HistoricalManual)<span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-900">{{ __('orders.ui_historical') }}</span>@elseif(!$order->entry_mode)<span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-sm">{{ __('orders.ui_legacy_entry') }}</span>@endif
</section>

<section class="space-y-4">
    <div><h3 class="font-semibold">{{ __('orders.ui_progress') }}</h3><p class="text-sm text-gray-600">{{ __('orders.ui_progress_help') }}</p></div>
    @php($missingDeliveryEvidence = $order->shops->filter(fn ($shop) => !$shop->picked_up_at || $shop->receipts->isEmpty()))
    @if($missingDeliveryEvidence->isEmpty() && $order->shops->isNotEmpty())<p class="rounded bg-green-50 p-3 text-green-800">{{ __('orders.ui_evidence_complete') }}</p>@else<p class="rounded bg-amber-50 p-3 text-amber-900">{{ __('orders.ui_evidence_blocked') }} {{ $missingDeliveryEvidence->pluck('shop_name_snapshot')->implode(', ') ?: __('orders.ui_add_one_shop') }}.</p>@endif
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach($order->shops as $orderShop)
            <article class="rounded bg-white p-5 shadow space-y-3 min-w-0">
                <h4 class="font-medium break-words">{{ $orderShop->shop_name_snapshot }}</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                    @foreach(\App\Services\OrderShopProgressService::MARKERS as $marker)
                        <div><span class="text-gray-600">{{ \App\Support\UiLabels::label('orders.progress_markers', $marker) }}:</span> {{ $orderShop->{$marker}?->format('Y-m-d H:i') ?? __('orders.ui_pending') }}</div>
                    @endforeach
                </div>
                @can('orders.update')
                    @if(!$order->cancellationIntent && !in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true) && $order->currentInvoice?->status !== \App\Enums\InvoiceStatus::Issued)
                        <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.shops.progress', [$order, $orderShop]) }}" class="flex flex-wrap gap-2">@csrf @method('PATCH')
                            <select name="marker" class="min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300">@foreach(\App\Services\OrderShopProgressService::MARKERS as $marker)@if(!$orderShop->{$marker})<option value="{{ $marker }}">{{ __('orders.ui_record_progress') }}: {{ \App\Support\UiLabels::label('orders.progress_markers', $marker) }}</option>@endif @endforeach</select>
                            <x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_record_progress') }}</x-primary-button>
                        </form>
                    @endif
                @endcan
                <div class="space-y-1"><p class="font-medium text-sm">{{ __('orders.ui_receipts') }}</p>@forelse($orderShop->receipts as $receipt)<p class="text-sm break-all"><a class="underline" href="{{ route('order-shop-receipts.show', [$order, $orderShop, $receipt]) }}" target="_blank" rel="noopener"><x-ltr>{{ $receipt->original_filename }}</x-ltr></a> · <x-ltr>{{ $receipt->uploaded_at->format('Y-m-d H:i') }}</x-ltr> · {{ $receipt->uploadedBy?->name ?? __('common.operational_former_user') }}</p>@empty<p class="text-sm text-gray-600">{{ __('orders.ui_no_receipts') }}</p>@endforelse</div>
                @can('order_shop_receipts.upload')
                    @if(!$order->cancellationIntent && $order->operational_status !== \App\Enums\OrderOperationalStatus::Delivered && ($order->operational_status !== \App\Enums\OrderOperationalStatus::Cancelled || $order->cancellation?->classification === \App\Enums\OrderCancellationClassification::AfterPurchase))
                        <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" enctype="multipart/form-data" action="{{ route('orders.shops.receipts.store', [$order, $orderShop]) }}" class="grid gap-2">@csrf<input type="file" class="max-w-full" name="receipt" accept="image/jpeg,image/png,image/webp" required><x-text-input name="note" placeholder="{{ __('common.operational_optional_note') }}" dir="auto"/><x-input-error :messages="$errors->get('note')" /><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_upload_receipt') }}</x-primary-button></form>
                    @endif
                @endcan
            </article>
        @endforeach
    </div>
</section>

@if($order->cancellation || $order->cancellationIntent)
<section class="rounded bg-white p-5 shadow space-y-4"><h3 class="font-semibold">{{ __('orders.ui_cancellation') }}</h3>
@else
<details class="rounded bg-white p-5 shadow"><summary class="cursor-pointer font-semibold text-red-700">{{ __('orders.ui_cancel_order_action') }}</summary><div class="mt-4 space-y-4">
@endif
    @if($order->cancellation)
        <p><strong>{{ \App\Support\UiLabels::label('orders.cancellation_classifications', $order->cancellation->classification) }}</strong> · {{ \App\Support\UiLabels::label('orders.cancellation_reasons', $order->cancellation->reason) }} · <x-ltr>{{ $order->cancellation->cancelled_at->format('Y-m-d H:i:s') }}</x-ltr> · {{ $order->cancellation->cancelledBy?->name ?? __('common.operational_former_user') }}</p>
        @if($order->cancellation->requiresFinancialResolution())<p class="rounded bg-amber-50 p-3 text-amber-900">{{ __('orders.ui_resolution_help') }}</p>@endif
        @if($order->cancellation->notes)<p class="whitespace-pre-wrap break-words">{{ $order->cancellation->notes }}</p>@endif
    @elseif(!$order->cancellationIntent && !in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
        @can('orders.cancel')<form x-data="{ submitting: false }" data-confirm="{{ __('orders.ui_confirm_sensitive_action') }}" x-on:submit="if (submitting) { $event.preventDefault(); } else { if (!confirm($el.dataset.confirm)) { $event.preventDefault(); return; } submitting = true; }" method="POST" action="{{ route('orders.cancel', $order) }}" class="grid gap-3 sm:grid-cols-2">@csrf<input type="hidden" name="classification" value="BEFORE_PURCHASE"><p class="text-sm sm:col-span-2">{{ __('orders.ui_before_help') }}</p><select name="reason" class="min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300">@foreach(\App\Enums\OrderCancellationReason::cases() as $reason)<option value="{{ $reason->value }}">{{ \App\Support\UiLabels::label('orders.cancellation_reasons', $reason) }}</option>@endforeach</select><textarea name="notes" class="rounded border-gray-300 sm:col-span-2" placeholder="{{ __('orders.ui_cancel_notes') }}" dir="auto"></textarea><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_cancel_before') }}</x-primary-button></form>@endcan
    @endif
@if($order->cancellation || $order->cancellationIntent)</section>@else</div></details>@endif

@if($order->operational_status === \App\Enums\OrderOperationalStatus::Delivered)
<section class="rounded bg-white p-5 shadow space-y-4">
    <h3 class="font-semibold">{{ __('orders.ui_ratings') }}</h3>
    @php($ratedCaptainIds = $order->captainRatings->pluck('captain_id'))
    @foreach($order->captainRatings as $rating)<p class="text-sm">{{ $rating->captain->user->name }} · <x-ltr>{{ $rating->rating }}</x-ltr>/5 · {{ \App\Support\UiLabels::label('orders.rating_sources', $rating->source) }} · <x-ltr>{{ $rating->rated_at->format('Y-m-d H:i') }}</x-ltr></p>@endforeach
    @can('captain_ratings.record')
        @foreach($order->captainAssignments->whereNull('removed_at')->unique('captain_id') as $assignment)
            @unless($ratedCaptainIds->contains($assignment->captain_id))<form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.captain-ratings.store', $order) }}" class="flex flex-wrap items-end gap-2">@csrf<input type="hidden" name="captain_id" value="{{ $assignment->captain_id }}"><div><x-input-label :value="__('orders.ui_rate_captain', ['name' => $assignment->captain->user->name])"/><select name="rating" class="min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300">@for($score = 5; $score >= 1; $score--)<option value="{{ $score }}">{{ $score }}/5</option>@endfor</select></div><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_save_rating') }}</x-primary-button></form>@endunless
        @endforeach
    @endcan
</section>
@endif
