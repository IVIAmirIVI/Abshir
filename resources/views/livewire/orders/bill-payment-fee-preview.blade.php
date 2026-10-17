<div class="min-w-0 space-y-2 sm:col-span-2 lg:col-span-3">
    <label class="block text-sm" for="bill-principal-{{ $requestId }}">{{ __('orders.ui_principal_label') }}</label>
    <x-text-input id="bill-principal-{{ $requestId }}" class="w-full min-w-0" name="principal_amount" inputmode="decimal" dir="ltr" wire:model.live.debounce.250ms="principal" placeholder="{{ __('orders.ui_principal') }}" required />
    <x-input-error :messages="$errors->get('principal_amount')" />
    @if($problem)<p class="text-sm text-red-700" role="alert">{{ $problem }}</p>@endif
    <div aria-live="polite" wire:loading.remove wire:target="principal">
        @if($preview)
            <dl class="flex flex-wrap gap-x-5 gap-y-2 text-sm" data-bill-fee-preview>
                <div><dt>{{ __('orders.ui_principal_label') }}</dt><dd><x-money :amount="$preview['principal']" :currency="$request->currency->code" /></dd></div>
                <div><dt>{{ __('bill_fees.service_fee') }}</dt><dd><x-money :amount="$preview['fee']" :currency="$request->currency->code" /></dd></div>
                <div><dt>{{ __('bill_fees.total') }}</dt><dd><x-money :amount="$preview['total']" :currency="$request->currency->code" /></dd></div>
            </dl>
        @else<p class="text-sm text-gray-600">{{ __('bill_fees.enter_principal') }}</p>@endif
    </div>
    <p wire:loading wire:target="principal" class="text-sm">{{ __('bill_fees.calculating') }}</p>
    @if(($request->feeRule->fee_mode ?? 'FIXED') === 'TIERED')
        @include('bill-payment-fees.policy', ['tiers' => $request->feeRule->fee_tiers])
    @endif
</div>
