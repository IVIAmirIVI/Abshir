<x-orders.section id="wizard-review">
    <h3 class="text-lg font-bold text-brand-primary">{{ __('orders.wizard.steps.review') }}</h3>
    <dl class="grid gap-4 sm:grid-cols-2">
        <div><dt class="text-sm text-gray-500">{{ __('common.terms.customer') }}</dt><dd class="font-semibold">{{ $this->selectedCustomer?->name }}</dd></div>
        <div><dt class="text-sm text-gray-500">{{ __('orders.ui_customer_phone') }}</dt><dd><bdi dir="ltr">{{ $this->phones->firstWhere('id', $customerPhoneId)?->phone }}</bdi></dd></div>
        <div class="sm:col-span-2"><dt class="text-sm text-gray-500">{{ __('orders.ui_delivery_address') }}</dt><dd class="break-words">{{ $this->addresses->firstWhere('id', $customerAddressId)?->address_line_1 }} — {{ $this->addresses->firstWhere('id', $customerAddressId)?->area->name }}</dd></div>
        <div><dt class="text-sm text-gray-500">{{ __('orders.ui_source_label') }}</dt><dd>{{ \App\Support\UiLabels::label('orders.sources', \App\Enums\OrderSource::tryFrom($source) ?? '') }}</dd></div>
        <div><dt class="text-sm text-gray-500">{{ __('orders.ui_entry_mode') }}</dt><dd>{{ \App\Support\UiLabels::label('orders.entry_modes', \App\Enums\OrderEntryMode::tryFrom($entryMode) ?? '') }} @if($orderedAt)<bdi dir="ltr">{{ $orderedAt }}</bdi>@endif</dd></div>
    </dl>
    <p class="text-xs leading-6 text-gray-500">{{ __('order_design.draft_prices') }}</p>
    @foreach($this->reviewGroups as $key => $group)
        <div wire:key="review-{{ $key }}" class="rounded-lg border border-gray-200 p-3"><h4 class="font-semibold">{{ $group['label'] }} — {{ $group['area_label'] }}</h4><ul class="mt-2 space-y-2 text-sm">@foreach($group['items'] as $item)<li class="break-words">{{ $item['label'] }} · <x-decimal :value="$item['quantity']" /> {{ $item['unit_label'] }}<x-orders.draft-totals /></li>@endforeach</ul><div class="mt-3 border-t border-stone-200 pt-3"><x-orders.draft-totals shop /></div></div>
    @endforeach
    @if($specialAddonApprovals)<section class="space-y-2 rounded-lg border p-3"><h4 class="font-semibold">{{ __('special.addon_title') }}</h4>@foreach($specialAddonApprovals as $key => $approval)@if(isset($groups[$key]))<p class="break-words text-sm">{{ collect($groups[$key]['items'])->pluck('label')->implode('، ') }} — {{ $approval['reason'] }}</p>@endif @endforeach</section>@endif
    @php($proposal = $this->deliveryProposal)
    @if(isset($proposal['special_sources']))<div class="space-y-2 rounded-lg border p-3"><h4 class="font-semibold">{{ __('special.title') }}</h4>@foreach($proposal['special_sources'] as $source)<p>{{ $source['label'] }} · <x-money :amount="(string) $source['quantity']" /> × <x-money :amount="$source['policy']->delivery_fee" :currency="$proposal['currency']->code" /> · {{ __('special.modes.'.$source['policy']->allocation_mode) }}</p>@endforeach<p class="text-sm text-gray-600">{{ __('special.help') }}</p></div>@endif
    @if($wizardCaptainIds)<div class="space-y-2 rounded-lg border p-3"><h4 class="font-semibold">{{ __('shared_orders.title') }}</h4>@foreach($this->wizardCaptainCandidates->whereIn('id', $wizardCaptainIds) as $participant)<p>{{ $participant->user->name }} @if((string) $participant->id === $wizardCollectorId) · {{ __('shared_orders.collector') }}@endif</p>@endforeach<p>{{ __('shared_orders.combined_delivery') }}: @if($this->wizardCombinedDelivery !== null)<x-money :amount="$this->wizardCombinedDelivery" />@else{{ __('common.operational_unavailable') }}@endif</p>@if(count($wizardCaptainIds) === 2)<p class="text-sm text-amber-900">{{ __('shared_orders.pending_finance') }}</p>@endif</div>@endif
    @if(isset($proposal['error']))<p role="alert" class="text-sm text-amber-800">{{ $proposal['error'] }}</p>@else<p class="font-semibold">{{ __('orders.wizard.suggested_fee') }}: <x-money :amount="$proposal['suggested_unit_fee']" :currency="$proposal['currency']->code" /></p>@endif
    @if($deliveryOverrideEnabled)
        @php($outcome = $this->deliveryOverrideOutcome)
        <div class="space-y-2 rounded-lg border bg-stone-50 p-3" data-wizard-override-review>
            <p>{{ __('wizard_delivery.requested') }}: <bdi dir="ltr">{{ $deliveryOverrideAmount }}</bdi></p>
            <p class="break-words">{{ __('wizard_delivery.reason') }}: {{ $deliveryOverrideReason }}</p>
            <p>{{ __('wizard_delivery.captain') }}: {{ $this->overrideCaptains->firstWhere('id', $deliveryOverrideCaptainId)?->user->name }}</p>
            @if(isset($outcome['error']))<p role="alert" class="text-amber-800">{{ $outcome['error'] }}</p>
            @elseif(isset($outcome['final_fee']))<x-orders.indicator :tone="$outcome['immediate'] ? 'approved' : 'pending'" data-wizard-approval-state>{{ __('order_design.'.($outcome['immediate'] ? 'approved' : 'pending')) }}</x-orders.indicator><p class="font-semibold" data-wizard-effective-fee>{{ __('wizard_delivery.final') }}: <x-money :amount="$outcome['final_fee']" :currency="$proposal['currency']->code" /></p><p class="text-sm">{{ __('wizard_delivery.'.($outcome['immediate'] ? 'immediate' : 'pending')) }}</p>@endif
        </div>
    @endif
    @if($notes)<div><h4 class="text-sm text-gray-500">{{ __('common.fields.notes') }}</h4><p class="whitespace-pre-wrap break-words text-sm">{{ $notes }}</p></div>@endif
    <p class="text-sm text-gray-600">{{ __('orders.wizard.review_help') }}</p>
</x-orders.section>
