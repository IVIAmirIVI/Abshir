@if(count($groups) > 1 && $this->specialDraftSources)
    @can('delivery_pricing.apply')
        <section class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
            <h4 class="font-semibold">{{ __('special.addon_title') }}</h4>
            <p class="text-sm">{{ __('special.addon_help') }}</p>
            <x-input-error :messages="$errors->get('special_delivery')" />
            @foreach($groups as $key => $group)
                @if(!collect($this->specialDraftSources)->contains(fn ($source) => $source['shop_key'] === $key))
                    <fieldset class="space-y-2 rounded-lg bg-white p-3" wire:key="addon-{{ $key }}">
                        <legend>{{ $group['shop_name'] ?? $this->shopMatches->firstWhere('id', $group['shop_id'] ?? null)?->name ?? __('orders.ui_registered_shop') }}</legend>
                        <p class="text-sm">{{ collect($group['items'])->pluck('label')->implode('، ') }}</p>
                        <label class="block text-sm">{{ __('special.addon_source') }}<select wire:model="addonInputs.{{ $key }}.source_key" class="mt-1 w-full rounded-lg border-gray-300"><option value="">—</option>@foreach($this->specialDraftSources as $source)<option value="{{ $source['key'] }}">{{ $source['label'] }}</option>@endforeach</select></label>
                        <label class="block text-sm">{{ __('special.addon_reason') }}<textarea wire:model="addonInputs.{{ $key }}.reason" maxlength="1000" class="mt-1 w-full rounded-lg border-gray-300"></textarea></label>
                        <x-orders.action type="button" variant="quiet" wire:click="approveDraftAddon('{{ $key }}')" wire:loading.attr="disabled">{{ __('special.addon_approve') }}</x-orders.action>
                        @if(isset($specialAddonApprovals[$key]) && $specialAddonApprovals[$key]['stamp'] === app(\App\Services\SpecialAddonApprovalService::class)->draftStamp($groups, $key, $specialAddonApprovals[$key]['source_key'], $branchId, $customerAddressId))<p class="text-sm text-green-800">{{ __('special.addon_approved') }}</p>@endif
                    </fieldset>
                @endif
            @endforeach
        </section>
    @endcan
@endif
