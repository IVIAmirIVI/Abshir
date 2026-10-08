@php($purchaseCaptainAssignments = $order->captainAssignments->sortBy('assigned_at'))
                @if($purchase = $orderShop->purchaseConfirmation)
                    <p class="text-sm">{{ __('orders.ui_verified_purchase') }} <strong><x-money :amount="$purchase->amount" /> <x-ltr>{{ $purchase->currency?->code }}</x-ltr></strong> · {{ __('orders.ui_payer') }} {{ $order->captainAssignments->firstWhere('id', $purchase->order_captain_id)?->captain?->user?->name }} · <x-ltr>{{ $purchase->purchased_at->format('Y-m-d H:i') }}</x-ltr></p>
                    @can('order_shop_purchases.view')<a class="underline text-sm" href="{{ route('orders.shops.purchases.evidence', [$order, $orderShop, $purchase]) }}" target="_blank" rel="noopener">{{ __('orders.ui_purchase_evidence') }}</a>@endcan
                @elseif(!($summaryOnly ?? false) && !$order->cancellationIntent && !in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
                    @can('order_shop_purchases.confirm')
                        @if($purchaseCaptainAssignments->isEmpty())
                            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('orders.ui_purchase_requires_order_captain') }}</p>
                        @else
                        <form method="POST" enctype="multipart/form-data" action="{{ route('orders.shops.purchases.store', [$order, $orderShop]) }}" x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" class="grid gap-2 text-sm">@csrf
                            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            @if($purchaseCaptainAssignments->count() === 1)<input type="hidden" name="order_captain_id" value="{{ $purchaseCaptainAssignments->sole()->id }}"><p>{{ __('orders.ui_payer') }} <strong>{{ $purchaseCaptainAssignments->sole()->captain?->user?->name }}</strong> @if($purchaseCaptainAssignments->sole()->removed_at)({{ __('orders.ui_historical_assignment') }})@endif</p>@else<label>{{ __('orders.ui_payer') }}<select name="order_captain_id" class="w-full rounded border-gray-300" required><option value="">{{ __('orders.ui_payer') }}</option>@foreach($purchaseCaptainAssignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->captain?->user?->name }} — {{ \App\Support\UiLabels::label('orders.captain_roles', $assignment->assignment_role) }} @if($assignment->removed_at)({{ __('orders.ui_historical_assignment') }})@endif</option>@endforeach</select><span class="mt-1 block text-xs text-gray-600">{{ __('orders.ui_payer_order_history_help') }}</span></label>@endif
                            @if($currencies->count() === 1)<input type="hidden" name="currency_id" value="{{ $currencies->sole()->id }}"><p>{{ __('common.terms.currency') }} <strong><x-ltr>{{ $currencies->sole()->code }}</x-ltr></strong></p>@else<label>{{ __('common.terms.currency') }}<select name="currency_id" class="w-full rounded border-gray-300" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('currency_id', $order->branch?->default_currency_id) == $currency->id)>{{ "\u{2066}".$currency->code."\u{2069}" }}</option>@endforeach</select></label>@endif
                            <x-input-error :messages="$errors->get('order_captain_id')" />
                            <x-input-error :messages="$errors->get('currency_id')" />
                            <label>{{ __('orders.ui_paid_amount') }}<x-text-input name="amount" class="w-full" :value="old('amount')" required inputmode="decimal" dir="ltr"/><x-input-error :messages="$errors->get('amount')" /></label>
                            <label>{{ __('orders.ui_purchased_at') }}<x-text-input type="datetime-local" name="purchased_at" class="w-full" value="{{ now()->format('Y-m-d\TH:i') }}" required dir="ltr" /></label>
                            <x-input-error :messages="$errors->get('purchased_at')" />
                            <label>{{ __('orders.ui_purchase_proof') }}<input type="file" class="max-w-full block w-full" name="evidence" accept="image/jpeg,image/png,image/webp" required><x-input-error :messages="$errors->get('evidence')" /></label>
                            @can('order_shop_receipts.upload')
                            <label class="flex items-start gap-2 rounded-lg border bg-stone-50 p-3"><input type="checkbox" name="also_shop_receipt" value="1" class="mt-1 shrink-0" @checked(old('also_shop_receipt'))><span>{{ __('order_execution.dual_confirmation') }}<small class="mt-1 block text-gray-600">{{ __('order_execution.dual_help') }}</small></span></label>
                            @endcan
                            <x-primary-button x-bind:disabled="submitting" class="justify-center disabled:opacity-50"><span x-show="!submitting">{{ __('orders.ui_confirm_purchase') }}</span><span x-show="submitting" x-cloak>{{ __('orders.ui_saving') }}</span></x-primary-button>
                        </form>
                        @endif
                    @endcan
                @else<p class="text-sm text-gray-600">{{ __('orders.ui_no_purchase') }}</p>@endif
