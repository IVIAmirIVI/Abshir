<x-orders.section id="wizard-delivery">
    <h3 class="text-lg font-bold text-brand-primary">{{ __('orders.wizard.steps.delivery') }}</h3>
    @php($proposal = $this->deliveryProposal)
    @if(isset($proposal['error']))<p role="alert" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $proposal['error'] }}</p>
    @else
        <div><h4 class="font-semibold">{{ __('orders.wizard.from') }}</h4><ul class="mt-2 space-y-2">@foreach($proposal['routes'] as $route)<li class="break-words text-sm">{{ $route['shop']->shop_name_snapshot }} — {{ $route['origin']->name }}</li>@endforeach</ul></div>
        <div><h4 class="font-semibold">{{ __('orders.wizard.to') }}</h4><p class="mt-2 break-words text-sm">{{ $this->addresses->firstWhere('id', $customerAddressId)?->address_line_1 }} — {{ $proposal['destination']->name }}</p></div>
        <div class="rounded-lg bg-stone-50 p-4"><h4 class="text-sm">{{ __('orders.wizard.suggested_fee') }}</h4><p class="mt-1 text-xl font-bold text-brand-primary"><bdi dir="ltr">{{ $proposal['currency']->code }} {{ $proposal['suggested_unit_fee'] }}</bdi></p></div>
        <p class="text-sm text-gray-600">{{ __('orders.wizard.pricing_help') }}</p>
        @can('delivery_pricing.override')
        <details class="rounded-lg border border-gray-200 p-3" @if($deliveryOverrideEnabled) open @endif data-wizard-override>
            <summary class="cursor-pointer text-sm font-semibold">{{ __('orders.wizard.override') }}</summary>
            @if(!$this->canUseDeliveryOverride)<p class="mt-2 text-sm">{{ __('wizard_delivery.permissions') }}</p>
            @elseif(!$deliveryOverrideEnabled)<button type="button" wire:click="beginDeliveryOverride" wire:loading.attr="disabled" class="mt-3 rounded-lg border border-brand-primary px-4 py-2 text-sm font-semibold">{{ __('wizard_delivery.enable') }}</button>
            @else
                <div class="mt-3 space-y-3">
                    <p class="text-sm text-gray-600">{{ __('wizard_delivery.captain_help') }}</p>
                    <label class="block text-sm">{{ __('wizard_delivery.requested') }}<input type="text" inputmode="decimal" dir="ltr" aria-invalid="{{ $errors->has('deliveryOverrideAmount') ? 'true' : 'false' }}" wire:model.live.debounce.250ms="deliveryOverrideAmount" class="mt-1 w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('deliveryOverrideAmount')" /></label>
                    <label class="block text-sm">{{ __('wizard_delivery.reason') }}<textarea aria-invalid="{{ $errors->has('deliveryOverrideReason') ? 'true' : 'false' }}" wire:model="deliveryOverrideReason" maxlength="3000" dir="auto" class="mt-1 w-full rounded-lg border-gray-300"></textarea><x-input-error :messages="$errors->get('deliveryOverrideReason')" /></label>
                    <label class="block text-sm">{{ __('wizard_delivery.captain') }}<select aria-invalid="{{ $errors->has('deliveryOverrideCaptainId') ? 'true' : 'false' }}" wire:model="deliveryOverrideCaptainId" class="mt-1 w-full rounded-lg border-gray-300"><option value="">{{ __('orders.wizard.choose') }}</option>@foreach($this->overrideCaptains as $captain)<option value="{{ $captain->id }}">{{ $captain->user->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('deliveryOverrideCaptainId')" /></label>
                    @if($this->overrideCaptains->isEmpty())<p class="text-sm text-amber-800">{{ __('wizard_delivery.no_captains') }}</p>@endif
                    @php($outcome = $this->deliveryOverrideOutcome)
                    @if(isset($outcome['error']))<p role="alert" class="text-sm text-amber-800">{{ $outcome['error'] }}</p>@endif
                    @if($outcome['context_changed'] ?? false)<button type="button" wire:click="reconfirmDeliveryOverride" class="text-sm font-semibold underline">{{ __('wizard_delivery.reconfirm') }}</button>@endif
                    @if(isset($outcome['final_fee']))<x-orders.indicator :tone="$outcome['immediate'] ? 'approved' : 'pending'" data-wizard-approval-state>{{ __('order_design.'.($outcome['immediate'] ? 'approved' : 'pending')) }}</x-orders.indicator><p class="font-semibold" data-wizard-effective-fee>{{ __('wizard_delivery.final') }}: <bdi dir="ltr">{{ $proposal['currency']->code }} {{ $outcome['final_fee'] }}</bdi></p><p class="text-sm">{{ __('wizard_delivery.'.($outcome['immediate'] ? 'immediate' : 'pending')) }}</p>@endif
                    <button type="button" wire:click="restoreAutomaticDelivery" class="text-sm font-semibold underline">{{ __('wizard_delivery.automatic') }}</button>
                </div>
            @endif
        </details>
        @endcan
    @endif
    <button type="button" wire:click="$refresh" class="text-sm font-semibold text-brand-primary">{{ __('orders.wizard.recalculate') }}</button>
    @if(!$deliveryOverrideEnabled)<p class="text-sm text-gray-600">{{ __('orders.wizard.captain_help') }}</p>@endif
</x-orders.section>
