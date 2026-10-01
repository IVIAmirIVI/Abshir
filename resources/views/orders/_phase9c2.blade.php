<section class="rounded bg-white p-5 shadow space-y-4">
    <div>
        <h3 class="font-semibold">Purchases and after-purchase returns</h3>
        <p class="text-sm text-gray-600">Each paid Shop purchase needs its actual payer, verified amount and private evidence. A cancellation intent freezes the purchased-Shop set; the Order is cancelled only after every Shop is returned and its refund is proved.</p>
    </div>

    @if($order->cancellationIntent)
        <div class="rounded bg-amber-50 p-3 text-sm text-amber-900">
            AFTER_PURCHASE intent recorded {{ $order->cancellationIntent->intent_at->format('Y-m-d H:i') }}.
            Normal delivery and financial advancement are frozen. Required returns:
            {{ $order->cancellationIntent->requiredShops->count() }};
            completed: {{ $order->shopReturns->count() }}.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach($order->shops as $orderShop)
            <article class="rounded border p-4 space-y-3 min-w-0">
                <h4 class="font-medium break-words">{{ $orderShop->shop_name_snapshot }}</h4>
                @if($purchase = $orderShop->purchaseConfirmation)
                    <p class="text-sm">Verified purchase: <strong>{{ $purchase->amount }} {{ $purchase->currency?->code }}</strong> · payer assignment #{{ $purchase->order_captain_id }} · {{ $purchase->purchased_at->format('Y-m-d H:i') }}</p>
                    @can('order_shop_purchases.view')<a class="underline text-sm" href="{{ route('orders.shops.purchases.evidence', [$order, $orderShop, $purchase]) }}" target="_blank" rel="noopener">View private purchase evidence</a>@endcan
                @elseif(!$order->cancellationIntent && !in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
                    @can('order_shop_purchases.confirm')
                        <form method="POST" enctype="multipart/form-data" action="{{ route('orders.shops.purchases.store', [$order, $orderShop]) }}" class="grid gap-2 text-sm">@csrf
                            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <label>Captain who paid<select name="order_captain_id" class="w-full rounded border-gray-300" required>@foreach($order->captainAssignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->captain?->user?->name }} (#{{ $assignment->id }})</option>@endforeach</select></label>
                            <label>Currency<select name="currency_id" class="w-full rounded border-gray-300" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('currency_id', $order->branch?->default_currency_id) == $currency->id)>{{ $currency->code }}</option>@endforeach</select></label>
                            <label>Actual paid amount<x-text-input name="amount" class="w-full" required inputmode="decimal" /></label>
                            <label>Purchased at<x-text-input type="datetime-local" name="purchased_at" class="w-full" value="{{ now()->format('Y-m-d\TH:i') }}" required /></label>
                            <label>Purchase proof<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full"></label>
                            <x-primary-button>Confirm purchase</x-primary-button>
                        </form>
                    @endcan
                @else<p class="text-sm text-gray-600">No authoritative purchase confirmation.</p>@endif

                @if($return = $orderShop->shopReturn)
                    <p class="rounded bg-green-50 p-2 text-sm text-green-900">Returned · refund {{ $return->refund_amount }} of {{ $return->purchase_amount }} to original purchasing Captain assignment #{{ $return->refund_recipient_order_captain_id }}.</p>
                    @if($return->shortfall_reason)<p class="text-sm text-amber-900">Exceptional shortfall: {{ $return->shortfallAmount() }} {{ $purchase?->currency?->code }} · {{ $return->shortfall_reason }}</p>@endif
                    @can('order_shop_return_evidence.download')
                        @foreach($return->evidence as $proof)
                            <a class="underline text-sm block" href="{{ route('orders.shops.returns.evidence', [$order, $orderShop, $return, $proof]) }}" target="_blank" rel="noopener">{{ str($proof->evidence_type)->replace('_', ' ')->title() }} evidence</a>
                        @endforeach
                    @endcan
                @elseif($purchase && $order->cancellationIntent && $order->cancellationIntent->requiredShops->contains('order_shop_id', $orderShop->id))
                    @can('order_shop_returns.create')
                        <form method="POST" enctype="multipart/form-data" action="{{ route('orders.shops.returns.store', [$order, $orderShop]) }}" class="grid gap-2 text-sm">@csrf
                            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <p>Refund recipient is fixed to original purchasing Captain assignment #{{ $purchase->order_captain_id }}.</p>
                            <label>Captain who physically returned<select name="returning_order_captain_id" class="w-full rounded border-gray-300" required>@foreach($order->captainAssignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->captain?->user?->name }} (#{{ $assignment->id }})</option>@endforeach</select></label>
                            <label>Refund received<x-text-input name="refund_amount" class="w-full" value="{{ $purchase->amount }}" required inputmode="decimal" /></label>
                            <p class="text-amber-900">If refund is below the verified purchase amount, record the exact difference and a reason. This records no ledger movement.</p>
                            <label>Shortfall reason, if any<textarea name="shortfall_reason" class="w-full rounded border-gray-300"></textarea></label>
                            <label class="flex items-start gap-2 text-amber-900"><input type="checkbox" name="shortfall_acknowledged" value="1" class="mt-1 rounded border-gray-300"><span>I confirm an exceptional short refund, if entered, has been received by the original purchasing Captain and its difference remains unresolved operational evidence.</span></label>
                            <label>Returned at<x-text-input type="datetime-local" name="returned_at" class="w-full" value="{{ now()->format('Y-m-d\TH:i') }}" required /></label>
                            <label>Refunded at<x-text-input type="datetime-local" name="refunded_at" class="w-full" value="{{ now()->format('Y-m-d\TH:i') }}" required /></label>
                            <label>Refund method<x-text-input name="refund_method" class="w-full" value="CASH" required /></label>
                            <label>Refund reference<x-text-input name="refund_reference" class="w-full" /></label>
                            <label>Combined return and refund proof<input type="file" name="combined_proof" accept="image/jpeg,image/png,image/webp" class="block w-full"></label>
                            <p>Or provide both separate proofs:</p>
                            <label>Return proof<input type="file" name="return_proof" accept="image/jpeg,image/png,image/webp" class="block w-full"></label>
                            <label>Refund proof<input type="file" name="refund_proof" accept="image/jpeg,image/png,image/webp" class="block w-full"></label>
                            <x-primary-button>Record completed return</x-primary-button>
                        </form>
                    @endcan
                @endif
            </article>
        @endforeach
    </div>

    @if(!$order->cancellationIntent && !$order->cancellation && $order->purchaseConfirmations->isNotEmpty() && (!in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true) || ($recoveredCancellationAvailable ?? false)))
        @can('order_cancellation_intents.create')
            <form method="POST" action="{{ route('orders.cancellation-intent.store', $order) }}" class="grid gap-2 max-w-xl text-sm">@csrf
                <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label>After-purchase cancellation reason<select name="reason" class="w-full rounded border-gray-300">@foreach(\App\Enums\OrderCancellationReason::cases() as $reason)<option value="{{ $reason->value }}">{{ $reason->label() }}</option>@endforeach</select></label>
                <label>Notes<textarea name="notes" class="w-full rounded border-gray-300"></textarea></label>
                <x-primary-button>Start return workflow</x-primary-button>
            </form>
        @endcan
    @elseif($order->cancellationIntent && !$order->cancellation && $order->shopReturns->count() === $order->cancellationIntent->requiredShops->count())
        @can('orders.cancel.finalize')<form method="POST" action="{{ route('orders.cancellation-finalize', $order) }}">@csrf<x-primary-button>Finalize cancellation</x-primary-button></form>@endcan
    @endif
</section>
