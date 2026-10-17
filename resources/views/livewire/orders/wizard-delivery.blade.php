<x-orders.section id="wizard-delivery">
    <h3 class="text-lg font-bold text-brand-primary">{{ __('orders.wizard.steps.delivery') }}</h3>
    @php($proposal = $this->deliveryProposal)
    @if(isset($proposal['special_sources']))
        <section class="space-y-3 rounded-lg border border-gray-200 p-3">
            <h4 class="font-semibold">{{ __('special.portions') }}</h4><p class="text-sm text-gray-600">{{ __('special.portion_help') }}</p>
            <x-input-error :messages="$errors->get('special_delivery')" />
            @foreach($proposal['special_sources'] as $source)
                <article class="space-y-2 rounded-lg bg-stone-50 p-3" wire:key="special-{{ $source['key'] }}">
                    <p class="font-medium">{{ $source['label'] }} · <x-money :amount="(string) $source['quantity']" /> × <x-money :amount="$source['policy']->delivery_fee" :currency="$proposal['currency']->code" /></p>
                    <p class="text-sm">{{ __('special.modes.'.$source['policy']->allocation_mode) }} @if($source['policy']->allocation_mode === 'FIXED') · {{ __('special.captain_fixed') }} <x-money :amount="$source['policy']->captain_fixed" /> · {{ __('special.company_fixed') }} <x-money :amount="$source['policy']->company_fixed" />@elseif($source['policy']->allocation_mode === 'PERCENTAGE') · <x-money :amount="$source['policy']->captain_percentage" />%@endif</p>
                    @if(count($wizardCaptainIds) > 1)<div class="grid gap-3 sm:grid-cols-2">@foreach($this->wizardCaptainCandidates->whereIn('id', $wizardCaptainIds) as $captain)<label wire:key="special-{{ $source['key'] }}-{{ $captain->id }}">{{ $captain->user->name }} — {{ __('special.units') }}<input type="text" inputmode="numeric" dir="ltr" wire:model.live="specialDeliveryAssignments.{{ $source['key'] }}.{{ $captain->id }}" class="mt-1 w-full rounded-lg border-gray-300"></label>@endforeach</div>@endif
                </article>
            @endforeach
        </section>
    @endif
    @if(isset($proposal['error']))<p role="alert" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $proposal['error'] }}</p>
    @else
        <div><h4 class="font-semibold">{{ __('orders.wizard.from') }}</h4><ul class="mt-2 space-y-2">@foreach($proposal['routes'] as $route)<li class="break-words text-sm">{{ $route['shop']->shop_name_snapshot }} — {{ $route['origin']->name }}</li>@endforeach</ul></div>
        <div><h4 class="font-semibold">{{ __('orders.wizard.to') }}</h4><p class="mt-2 break-words text-sm">{{ $this->addresses->firstWhere('id', $customerAddressId)?->address_line_1 }} — {{ $proposal['destination']->name }}</p></div>
        <div class="rounded-lg bg-stone-50 p-4"><h4 class="text-sm">{{ __('orders.wizard.suggested_fee') }}</h4><p class="mt-1 text-xl font-bold text-brand-primary"><x-money :amount="$proposal['suggested_unit_fee']" :currency="$proposal['currency']->code" /></p></div>
        <p class="text-sm text-gray-600">{{ __('orders.wizard.pricing_help') }}</p>
        @if($this->canSelectWizardCaptains)
        <section class="space-y-3 rounded-lg border p-3" aria-labelledby="wizard-captain-title">
            <h4 id="wizard-captain-title" class="font-semibold">{{ __('shared_orders.title') }}</h4>
            <label class="block text-sm" for="wizard-primary-captain">{{ __('shared_orders.primary') }}</label>
            <select id="wizard-primary-captain" wire:model.live="wizardCaptainIds.0" class="w-full rounded-lg border-gray-300">
                <option value="">{{ __('orders.ui_choose_captain') }}</option>
                @foreach($this->wizardCaptainCandidates as $candidate)<option value="{{ $candidate->id }}" @disabled((string) $candidate->id === (string) ($wizardCaptainIds[1] ?? ''))>{{ $candidate->user->name }}</option>@endforeach
            </select>
            <x-input-error :messages="$errors->get('wizardCaptainIds.0')" />
            <x-input-error :messages="$errors->get('wizardCaptainIds')" />
            @if(!$wizardSecondCaptainEnabled)
                <x-orders.action id="wizard-add-second-captain" type="button" variant="quiet" wire:click="addSecondWizardCaptain" wire:loading.attr="disabled" wire:target="addSecondWizardCaptain">{{ __('shared_orders.add_second') }}</x-orders.action>
            @else
                <label class="block text-sm" for="wizard-second-captain">{{ __('shared_orders.second') }}</label>
                <select id="wizard-second-captain" wire:model.live="wizardCaptainIds.1" class="w-full rounded-lg border-gray-300">
                    <option value="">{{ __('orders.ui_choose_captain') }}</option>
                    @foreach($this->wizardCaptainCandidates as $candidate)<option value="{{ $candidate->id }}" @disabled((string) $candidate->id === (string) ($wizardCaptainIds[0] ?? ''))>{{ $candidate->user->name }}</option>@endforeach
                </select>
                <x-input-error :messages="$errors->get('wizardCaptainIds.1')" />
                <x-orders.action id="wizard-remove-second-captain" type="button" variant="quiet" wire:click="removeSecondWizardCaptain" wire:loading.attr="disabled" wire:target="removeSecondWizardCaptain">{{ __('shared_orders.remove_second') }}</x-orders.action>
                @if(count($wizardCaptainIds) === 2)
                    <label class="block text-sm" for="wizard-collector">{{ __('shared_orders.collector') }}</label>
                    <select id="wizard-collector" wire:model.live="wizardCollectorId" class="w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_captain') }}</option>@foreach($this->wizardCaptainCandidates->whereIn('id', $wizardCaptainIds) as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->user->name }}</option>@endforeach</select>
                    <x-input-error :messages="$errors->get('wizardCollectorId')" />
                    <p class="text-sm text-gray-600">{{ __('shared_orders.collector_help') }}</p>
                    <p class="text-sm text-amber-900">{{ __('shared_orders.pending_finance') }}</p>
                @endif
            @endif
            @if(count($wizardCaptainIds) === 1)<p class="text-sm text-gray-600">{{ __('shared_orders.single_collector') }}</p>@endif
        </section>
        @endif
        @if($wizardCaptainIds && $this->wizardCombinedDelivery !== null)<p class="font-semibold">{{ __('shared_orders.combined_delivery') }}: <x-money :amount="$this->wizardCombinedDelivery" :currency="$proposal['currency']->code" /></p>@endif
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
                    <p class="text-sm">{{ __('shared_orders.collector') }}: {{ $this->wizardCaptainCandidates->firstWhere('id', $wizardCollectorId)?->user->name ?? __('shared_orders.choose_primary_above') }}</p>
                    <x-input-error :messages="$errors->get('deliveryOverrideCaptainId')" />
                    @if($this->overrideCaptains->isEmpty())<p class="text-sm text-amber-800">{{ __('wizard_delivery.no_captains') }}</p>@endif
                    @php($outcome = $this->deliveryOverrideOutcome)
                    @if(isset($outcome['error']))<p role="alert" class="text-sm text-amber-800">{{ $outcome['error'] }}</p>@endif
                    @if($outcome['context_changed'] ?? false)<x-orders.action type="button" variant="quiet" wire:click="reconfirmDeliveryOverride">{{ __('wizard_delivery.reconfirm') }}</x-orders.action>@endif
                    @if(isset($outcome['final_fee']))<x-orders.indicator :tone="$outcome['immediate'] ? 'approved' : 'pending'" data-wizard-approval-state>{{ __('order_design.'.($outcome['immediate'] ? 'approved' : 'pending')) }}</x-orders.indicator><p class="font-semibold" data-wizard-effective-fee>{{ __('wizard_delivery.final') }}: <x-money :amount="$outcome['final_fee']" :currency="$proposal['currency']->code" /></p><p class="text-sm">{{ __('wizard_delivery.'.($outcome['immediate'] ? 'immediate' : 'pending')) }}</p>@endif
                    <x-orders.action type="button" variant="quiet" wire:click="restoreAutomaticDelivery">{{ __('wizard_delivery.automatic') }}</x-orders.action>
                </div>
            @endif
        </details>
        @endcan
    @endif
    <x-orders.action type="button" variant="quiet" wire:click="$refresh">{{ __('orders.wizard.recalculate') }}</x-orders.action>
    @if(!$deliveryOverrideEnabled)<p class="text-sm text-gray-600">{{ __('orders.wizard.captain_help') }}</p>@endif
</x-orders.section>
