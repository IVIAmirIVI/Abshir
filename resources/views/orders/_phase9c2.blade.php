<section class="rounded bg-white p-5 shadow space-y-4">
    @php($purchaseCaptainAssignments = $order->captainAssignments->sortBy('assigned_at'))
    <div>
        <h3 class="font-semibold">{{ $order->cancellationIntent || $order->shopReturns->isNotEmpty() ? __('orders.ui_returns') : __('orders.ui_purchase_progress') }}</h3>
        <p class="text-sm text-gray-600">{{ $order->cancellationIntent || $order->shopReturns->isNotEmpty() ? __('orders.ui_returns_help') : __('orders.ui_purchase_progress_help') }}</p>
    </div>

    @if($order->cancellationIntent)
        <div class="rounded bg-amber-50 p-3 text-sm text-amber-900">
            {{ __('orders.ui_intent_recorded') }} <x-ltr>{{ $order->cancellationIntent->intent_at->format('Y-m-d H:i') }}</x-ltr>.
            {{ __('orders.ui_intent_frozen') }}
            {{ $order->cancellationIntent->requiredShops->count() }};
            {{ __('orders.ui_completed_count') }} {{ $order->shopReturns->count() }}.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach($order->shops as $orderShop)
            <article class="rounded border p-4 space-y-3 min-w-0">
                <h4 class="font-medium break-words">{{ $orderShop->shop_name_snapshot }}</h4>
                @if($purchase = $orderShop->purchaseConfirmation)
                    <p class="text-sm">{{ __('orders.ui_verified_purchase') }} <strong><x-money :amount="$purchase->amount" /> <x-ltr>{{ $purchase->currency?->code }}</x-ltr></strong> · {{ __('orders.ui_payer_assignment') }}<x-ltr>{{ $purchase->order_captain_id }}</x-ltr> · <x-ltr>{{ $purchase->purchased_at->format('Y-m-d H:i') }}</x-ltr></p>
                    @can('order_shop_purchases.view')<a class="underline text-sm" href="{{ route('orders.shops.purchases.evidence', [$order, $orderShop, $purchase]) }}" target="_blank" rel="noopener">{{ __('orders.ui_purchase_evidence') }}</a>@endcan
                @elseif(!$order->cancellationIntent && !in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
                    @can('order_shop_purchases.confirm')
                        @if($purchaseCaptainAssignments->isEmpty())
                            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('orders.ui_purchase_requires_order_captain') }}</p>
                        @else
                        <form method="POST" enctype="multipart/form-data" action="{{ route('orders.shops.purchases.store', [$order, $orderShop]) }}" x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" class="grid gap-2 text-sm">@csrf
                            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            @if($purchaseCaptainAssignments->count() === 1)<input type="hidden" name="order_captain_id" value="{{ $purchaseCaptainAssignments->sole()->id }}"><p>{{ __('orders.ui_payer') }} <strong>{{ $purchaseCaptainAssignments->sole()->captain?->user?->name }}</strong> @if($purchaseCaptainAssignments->sole()->removed_at)({{ __('orders.ui_historical_assignment') }})@endif</p>@else<label>{{ __('orders.ui_payer') }}<select name="order_captain_id" class="w-full rounded border-gray-300" required>@foreach($purchaseCaptainAssignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->captain?->user?->name }} — {{ \App\Support\UiLabels::label('orders.captain_roles', $assignment->assignment_role) }} @if($assignment->removed_at)({{ __('orders.ui_historical_assignment') }})@endif</option>@endforeach</select><span class="mt-1 block text-xs text-gray-600">{{ __('orders.ui_payer_order_history_help') }}</span></label>@endif
                            @if($currencies->count() === 1)<input type="hidden" name="currency_id" value="{{ $currencies->sole()->id }}"><p>{{ __('common.terms.currency') }} <strong><x-ltr>{{ $currencies->sole()->code }}</x-ltr></strong></p>@else<label>{{ __('common.terms.currency') }}<select name="currency_id" class="w-full rounded border-gray-300" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('currency_id', $order->branch?->default_currency_id) == $currency->id)>{{ "\u{2066}".$currency->code."\u{2069}" }}</option>@endforeach</select></label>@endif
                            <x-input-error :messages="$errors->get('order_captain_id')" />
                            <x-input-error :messages="$errors->get('currency_id')" />
                            <label>{{ __('orders.ui_paid_amount') }}<x-text-input name="amount" class="w-full" :value="old('amount')" required inputmode="decimal" dir="ltr"/><x-input-error :messages="$errors->get('amount')" /></label>
                            <label>{{ __('orders.ui_purchased_at') }}<x-text-input type="datetime-local" name="purchased_at" class="w-full" value="{{ now()->format('Y-m-d\TH:i') }}" required dir="ltr" /></label>
                            <x-input-error :messages="$errors->get('purchased_at')" />
                            <label>{{ __('orders.ui_purchase_proof') }}<input type="file" class="max-w-full block w-full" name="evidence" accept="image/jpeg,image/png,image/webp" required><x-input-error :messages="$errors->get('evidence')" /></label>
                            <x-primary-button x-bind:disabled="submitting" class="justify-center disabled:opacity-50"><span x-show="!submitting">{{ __('orders.ui_confirm_purchase') }}</span><span x-show="submitting" x-cloak>{{ __('orders.ui_saving') }}</span></x-primary-button>
                        </form>
                        @endif
                    @endcan
                @else<p class="text-sm text-gray-600">{{ __('orders.ui_no_purchase') }}</p>@endif

                @if($return = $orderShop->shopReturn)
                    <p class="rounded bg-green-50 p-2 text-sm text-green-900">{{ __('orders.ui_returned_refund') }} <x-money :amount="$return->refund_amount" /> {{ __('orders.ui_of') }} <x-money :amount="$return->purchase_amount" /> {{ __('orders.ui_refund_to_original') }}<x-ltr>{{ $return->refund_recipient_order_captain_id }}</x-ltr>.</p>
                    @if($return->shortfall_reason)<p class="text-sm text-amber-900">{{ __('orders.ui_exceptional_shortfall') }} <x-money :amount="$return->shortfallAmount()" /> <x-ltr>{{ $purchase?->currency?->code }}</x-ltr> · {{ $return->shortfall_reason }}</p>@endif
                    @can('order_shop_return_evidence.download')
                        @foreach($return->evidence as $proof)
                            <a class="underline text-sm block" href="{{ route('orders.shops.returns.evidence', [$order, $orderShop, $return, $proof]) }}" target="_blank" rel="noopener">{{ \App\Support\UiLabels::label('orders.return_evidence', $proof->evidence_type) }}</a>
                        @endforeach
                    @endcan
                @elseif($purchase && $order->cancellationIntent && $order->cancellationIntent->requiredShops->contains('order_shop_id', $orderShop->id))
                    @can('order_shop_returns.create')
                        <form x-data="{ submitting: false }" data-confirm="{{ __('orders.ui_confirm_sensitive_action') }}" x-on:submit="if (submitting) { $event.preventDefault(); } else { if (!confirm($el.dataset.confirm)) { $event.preventDefault(); return; } submitting = true; }" method="POST" enctype="multipart/form-data" action="{{ route('orders.shops.returns.store', [$order, $orderShop]) }}" class="grid gap-2 text-sm">@csrf
                            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <p>{{ __('orders.ui_refund_fixed') }}<x-ltr>{{ $purchase->order_captain_id }}</x-ltr>.</p>
                            <label>{{ __('orders.ui_returning_captain') }}<select name="returning_order_captain_id" class="w-full rounded border-gray-300" required>@foreach($purchaseCaptainAssignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->captain?->user?->name }} — {{ \App\Support\UiLabels::label('orders.captain_roles', $assignment->assignment_role) }}</option>@endforeach</select></label>
                            <label>{{ __('orders.ui_refund_received') }}<x-text-input name="refund_amount" class="w-full" value="{{ $purchase->amount }}" required inputmode="decimal" dir="ltr" /></label>
                            <p class="text-amber-900">{{ __('orders.ui_shortfall_help') }}</p>
                            <label>{{ __('orders.ui_shortfall_reason') }}<textarea name="shortfall_reason" class="w-full rounded border-gray-300" dir="auto"></textarea></label>
                            <label class="flex items-start gap-2 text-amber-900"><input type="checkbox" name="shortfall_acknowledged" value="1" class="mt-1 rounded border-gray-300"><span>{{ __('orders.ui_shortfall_acknowledge') }}</span></label>
                            <label>{{ __('orders.ui_returned_at') }}<x-text-input type="datetime-local" name="returned_at" class="w-full" value="{{ now()->format('Y-m-d\TH:i') }}" required dir="ltr" /></label>
                            <label>{{ __('orders.ui_refunded_at') }}<x-text-input type="datetime-local" name="refunded_at" class="w-full" value="{{ now()->format('Y-m-d\TH:i') }}" required dir="ltr" /></label>
                            <label>{{ __('orders.ui_refund_method') }}<x-text-input name="refund_method" class="w-full" value="CASH" required  dir="ltr"/><x-input-error :messages="$errors->get('refund_method')" /></label>
                            <label>{{ __('orders.ui_refund_reference') }}<x-text-input name="refund_reference" class="w-full"  dir="ltr"/><x-input-error :messages="$errors->get('refund_reference')" /></label>
                            <label>{{ __('orders.ui_combined_proof') }}<input type="file" class="max-w-full block w-full" name="combined_proof" accept="image/jpeg,image/png,image/webp"></label>
                            <p>{{ __('orders.ui_separate_proofs') }}</p>
                            <label>{{ __('orders.ui_return_proof') }}<input type="file" class="max-w-full block w-full" name="return_proof" accept="image/jpeg,image/png,image/webp"></label>
                            <label>{{ __('orders.ui_refund_proof') }}<input type="file" class="max-w-full block w-full" name="refund_proof" accept="image/jpeg,image/png,image/webp"></label>
                            <x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_record_return') }}</x-primary-button>
                        </form>
                    @endcan
                @endif
            </article>
        @endforeach
    </div>

    @if(!$order->cancellationIntent && !$order->cancellation && $order->purchaseConfirmations->isNotEmpty() && (!in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true) || ($recoveredCancellationAvailable ?? false)))
        @can('order_cancellation_intents.create')
            <details class="rounded-lg border border-red-100 p-3"><summary class="cursor-pointer font-medium text-red-700">{{ __('orders.ui_start_return') }}</summary>
            <form x-data="{ submitting: false }" data-confirm="{{ __('orders.ui_confirm_sensitive_action') }}" x-on:submit="if (submitting) { $event.preventDefault(); } else { if (!confirm($el.dataset.confirm)) { $event.preventDefault(); return; } submitting = true; }" method="POST" action="{{ route('orders.cancellation-intent.store', $order) }}" class="grid gap-2 max-w-xl text-sm">@csrf
                <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label>{{ __('orders.ui_after_reason') }}<select name="reason" class="w-full rounded border-gray-300">@foreach(\App\Enums\OrderCancellationReason::cases() as $reason)<option value="{{ $reason->value }}">{{ \App\Support\UiLabels::label('orders.cancellation_reasons', $reason) }}</option>@endforeach</select></label>
                <label>{{ __('common.fields.notes') }}<textarea name="notes" class="w-full rounded border-gray-300" dir="auto"></textarea></label>
                <x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_start_return') }}</x-primary-button>
            </form>
            </details>
        @endcan
    @elseif($order->cancellationIntent && !$order->cancellation && $order->shopReturns->count() === $order->cancellationIntent->requiredShops->count())
        @can('orders.cancel.finalize')<form x-data="{ submitting: false }" data-confirm="{{ __('orders.ui_confirm_sensitive_action') }}" x-on:submit="if (submitting) { $event.preventDefault(); } else { if (!confirm($el.dataset.confirm)) { $event.preventDefault(); return; } submitting = true; }" method="POST" action="{{ route('orders.cancellation-finalize', $order) }}">@csrf<x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_finalize_cancel') }}</x-primary-button></form>@endcan
    @endif
</section>
