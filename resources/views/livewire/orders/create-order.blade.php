<form wire:submit="submitWizard" data-order-create-wizard class="mary-surface order-ux min-w-0 space-y-6 break-words" data-theme="abshir" novalidate>
    <header class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold text-brand-primary">{{ __('order_design.draft') }}</p><h2 class="mt-1 text-2xl font-bold">{{ __('orders.ui_create') }}</h2><p class="mt-2 text-sm leading-6 text-gray-600">{{ __('order_design.draft_help') }}</p></div><x-orders.indicator>{{ __('order_design.step', ['number' => $step, 'total' => 4]) }}</x-orders.indicator></header>
    <nav aria-label="{{ __('orders.wizard.steps_label') }}" class="grid grid-cols-2 gap-2 sm:grid-cols-4" data-order-stepper>
        @foreach(['customer', 'shops', 'delivery', 'review'] as $index => $label)
            <button type="button" wire:click="goToStep({{ $index + 1 }})" aria-current="{{ $step === $index + 1 ? 'step' : 'false' }}" @class(['min-w-0 rounded-lg border p-3 text-start text-sm font-semibold', 'border-brand-primary bg-brand-primary text-white' => $step === $index + 1, 'border-gray-200 bg-white text-gray-600' => $step !== $index + 1])><x-orders.step-label :number="$index + 1" :title="__('orders.wizard.steps.'.$label)" :current="$step === $index + 1" /></button>
        @endforeach
    </nav>
    <p wire:loading.class.remove="invisible" role="status" class="invisible min-h-5 text-sm font-medium text-brand-primary">{{ __('order_design.working') }}</p>
    @if($this->selectedCustomer)<aside class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-stone-200 bg-white p-4 text-sm" data-wizard-selection-summary><span class="text-xs text-gray-500">{{ __('order_design.selected') }}</span><strong>{{ $this->selectedCustomer->name }}</strong><x-ltr>{{ $this->phones->firstWhere('id', $customerPhoneId)?->phone }}</x-ltr><span class="min-w-0 break-words">{{ $this->addresses->firstWhere('id', $customerAddressId)?->address_line_1 }}</span>@foreach($groups as $selectedGroup)<x-orders.indicator>{{ $selectedGroup['label'] }}</x-orders.indicator>@endforeach</aside>@endif
    <x-input-error :messages="$step === 2 ? collect($errors->messages())->except('groups')->flatten()->all() : $errors->all()" role="alert" />
    @if(session('order-create-status'))<p class="rounded-lg bg-green-50 p-3 text-sm text-green-900">{{ session('order-create-status') }}</p>@endif
    <div wire:key="wizard-step-{{ $step }}">
    @if($step === 1)
    <x-orders.section id="wizard-customer">
        <div class="mb-5">
            <h3 class="text-lg font-bold text-brand-primary">{{ __('orders.ui_customer_delivery_step') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('orders.ui_customer_delivery_help') }}</p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">{{ __('common.terms.branch') }}</span>
                @if($this->branches->count() === 1)
                    <input type="hidden" aria-invalid="{{ $errors->has('branchId') ? 'true' : 'false' }}" wire:model="branchId"><p class="mt-1 rounded-lg bg-stone-50 p-3 font-medium">{{ $this->branches->sole()->name }}</p>
                @else
                <select name="branch_id" aria-invalid="{{ $errors->has('branchId') ? 'true' : 'false' }}" wire:model.live="branchId" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500 ring-red-200' => $errors->has('branchId'), 'border-gray-300' => ! $errors->has('branchId')])>
                    <option value="">{{ __('orders.ui_choose_branch') }}</option>
                    @foreach($this->branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                </select>
                @endif
                <x-input-error :messages="$errors->get('branchId')" class="mt-1" />
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('common.terms.customer') }}</span>
                <input type="hidden" name="customer_id" aria-invalid="{{ $errors->has('customerId') ? 'true' : 'false' }}" wire:model="customerId">
                <x-orders.wizard-search model="customerSearch" choose="selectCustomer" sole="selectSoleCustomer" :query="$customerSearch" :label="__('orders.ui_choose_customer')">
                    @foreach($this->customers as $customer)
                        <button type="button" role="option" aria-selected="{{ (string) $customer->id === $customerId ? 'true' : 'false' }}" wire:click="selectCustomer({{ $customer->id }})" x-on:click="open = false" class="block w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-stone-100 focus:bg-stone-100 focus:outline-none">
                            {{ $customer->name }} @if($phone = $customer->phones->sortByDesc('is_primary')->first()) — <bdi dir="ltr">{{ $phone->phone }}</bdi>@endif @if($address = $customer->addresses->first()) — {{ $address->area->name }} · {{ $address->address_line_1 }}@endif
                        </button>
                    @endforeach
                    @if($this->customers->isEmpty())<p class="p-3 text-sm text-gray-500">{{ __('orders.wizard.no_results') }}</p>@endif
                </x-orders.wizard-search>
                <p class="mt-1 text-xs text-gray-600">{{ __('orders.ui_search_selection_hint') }}</p>
                @if($this->selectedCustomer)<p wire:key="wizard-selected-customer-{{ $customerId }}" class="mt-2 rounded-lg bg-stone-50 p-2 text-sm" data-wizard-selected-customer>{{ __('wizard_delivery.selected_customer') }}: <strong>{{ $this->selectedCustomer->name }}</strong></p>@endif
                <x-input-error :messages="$errors->get('customerId')" class="mt-1" />
                @can('customers.create')<x-orders.action type="button" variant="quiet" wire:click="toggleCustomerCreate" class="mt-2">{{ __('orders.ui_add_new_customer') }}</x-orders.action>@endcan
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_customer_phone') }}</span>
                @if($this->phones->count() === 1)
                    <input type="hidden" aria-invalid="{{ $errors->has('customerPhoneId') ? 'true' : 'false' }}" wire:model="customerPhoneId"><p class="mt-1 rounded-lg bg-stone-50 p-3" dir="ltr">{{ $this->phones->sole()->phone }}</p>
                @else
                <select dir="ltr" name="customer_phone_id" aria-invalid="{{ $errors->has('customerPhoneId') ? 'true' : 'false' }}" wire:model="customerPhoneId" @disabled(! $customerId) @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500 ring-red-200' => $errors->has('customerPhoneId'), 'border-gray-300' => ! $errors->has('customerPhoneId')])>
                    <option value="">{{ $customerId ? __('orders.ui_choose_phone') : __('orders.ui_choose_customer_first') }}</option>
                    @foreach($this->phones as $phone)<option value="{{ $phone->id }}">{{ $phone->phone }}</option>@endforeach
                </select>
                @endif
                <x-input-error :messages="$errors->get('customerPhoneId')" class="mt-1" />
                @can('customers.update')<x-orders.action type="button" variant="quiet" wire:click="openPhoneCreate" :disabled="!$customerId" class="mt-2">{{ __('order_ux.add_phone') }}</x-orders.action>@endcan
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_delivery_address') }}</span>
                @if($this->addresses->count() === 1)
                    @php($singleAddress = $this->addresses->sole())
                    <input type="hidden" aria-invalid="{{ $errors->has('customerAddressId') ? 'true' : 'false' }}" wire:model="customerAddressId"><p class="mt-1 rounded-lg bg-stone-50 p-3">{{ $singleAddress->address_line_1 }} — {{ $singleAddress->area->name }}</p>
                @else
                <select name="customer_address_id" aria-invalid="{{ $errors->has('customerAddressId') ? 'true' : 'false' }}" wire:model="customerAddressId" @disabled(! $customerId || ! $branchId) @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500 ring-red-200' => $errors->has('customerAddressId'), 'border-gray-300' => ! $errors->has('customerAddressId')])>
                    <option value="">{{ ($customerId && $branchId) ? __('orders.ui_choose_address') : __('orders.ui_choose_customer_branch_first') }}</option>
                    @foreach($this->addresses as $address)
                        <option value="{{ $address->id }}">{{ $address->address_line_1 }} — {{ $address->area->name }}{{ $address->area->parent ? ' ('.$address->area->parent->name.')' : '' }}</option>
                    @endforeach
                </select>
                @endif
                @if($customerId && $branchId && $this->addresses->isEmpty())<p class="mt-1 text-sm text-amber-700">{{ __('orders.ui_no_customer_addresses') }}</p>@endif
                @if($customerId) @can('customers.update')<x-orders.action type="button" variant="quiet" wire:click="openAddressCreate" class="mt-2">{{ __('orders.wizard.add_address') }}</x-orders.action>@endcan @endif
                <x-input-error :messages="$errors->get('customerAddressId')" class="mt-1" />
            </label>
        </div>


    </x-orders.section>

    <x-orders.section id="wizard-details">
        <h3 class="mb-5 text-lg font-bold text-brand-primary">{{ __('orders.ui_order_details_step') }}</h3>
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_source_label') }}</span>
                <select name="source" aria-invalid="{{ $errors->has('source') ? 'true' : 'false' }}" wire:model="source" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500' => $errors->has('source'), 'border-gray-300' => ! $errors->has('source')])>
                    <option value="">{{ __('orders.ui_choose_source') }}</option>
                    @foreach($sources as $choice)<option value="{{ $choice->value }}">{{ \App\Support\UiLabels::label('orders.sources', $choice) }}</option>@endforeach
                </select>
                <x-input-error :messages="$errors->get('source')" class="mt-1" />
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_entry_mode') }}</span>
                <select name="entry_mode" aria-invalid="{{ $errors->has('entryMode') ? 'true' : 'false' }}" wire:model.live="entryMode" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                    @foreach($entryModes as $mode)<option value="{{ $mode->value }}">{{ \App\Support\UiLabels::label('orders.entry_modes', $mode) }}</option>@endforeach
                </select>
                <x-input-error :messages="$errors->get('entryMode')" class="mt-1" />
            </label>

            @if($entryMode === 'HISTORICAL_MANUAL')
                <label class="block sm:col-span-2" wire:key="historical-order-time">
                    <span class="text-sm font-medium">{{ __('orders.ui_historical_time') }}</span>
                    <input name="ordered_at" type="datetime-local" aria-invalid="{{ $errors->has('orderedAt') ? 'true' : 'false' }}" wire:model="orderedAt" max="{{ now()->format('Y-m-d\TH:i') }}" dir="ltr" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500' => $errors->has('orderedAt'), 'border-gray-300' => ! $errors->has('orderedAt')])>
                    <p class="mt-1 text-xs text-gray-600">{{ __('orders.ui_entry_time_help') }}</p>
                    <x-input-error :messages="$errors->get('orderedAt')" class="mt-1" />
                </label>
            @endif

            <label class="block sm:col-span-2">
                <span class="text-sm font-medium">{{ __('common.fields.notes') }}</span>
                <textarea name="notes" aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}" wire:model="notes" rows="3" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm"></textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </label>
        </div>
    </x-orders.section>

    @elseif($step === 2)
        @include('livewire.orders.wizard-shops')
    @elseif($step === 3)
        @include('livewire.orders.wizard-delivery')
        @if($this->selectedCustomer && $branchId !== '')
            @include('customers._credit', ['creditCustomer' => $this->selectedCustomer, 'creditBranchId' => (int) $branchId])
        @endif
    @else
        @include('livewire.orders.wizard-review')
    @endif
    </div>
            @if($showCustomerCreate)
            @can('customers.create')
                <div wire:key="create-customer-modal" role="dialog" aria-modal="true" aria-labelledby="create-customer-title" data-customer-create-modal x-data x-trap.inert.noscroll="true" x-on:keydown.escape.stop="$wire.closeCustomerCreate()" x-on:keydown.enter="if ($event.target.matches('input:not(.select2-search__field)')) { $event.preventDefault(); $wire.createCustomer(); }" class="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6">
                    <button type="button" wire:click="closeCustomerCreate" class="fixed inset-0 bg-gray-900/50" tabindex="-1" aria-label="{{ __('common.actions.cancel') }}"></button>
                    <div class="relative mx-auto w-full max-w-2xl rounded-xl bg-white p-5 shadow-xl sm:p-6">
                        <div class="mb-5 flex items-center justify-between gap-3"><h4 id="create-customer-title" class="text-lg font-semibold text-brand-primary">{{ __('orders.ui_add_new_customer') }}</h4><x-orders.action type="button" variant="secondary" wire:click="closeCustomerCreate">{{ __('common.actions.cancel') }}</x-orders.action></div>
                        <div class="grid gap-4 sm:grid-cols-2">
                    <label><span class="text-sm font-medium">{{ __('common.fields.name') }}</span><input aria-invalid="{{ $errors->has('newCustomerName') ? 'true' : 'false' }}" wire:model="newCustomerName" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newCustomerName')" /></label>
                    <label><span class="text-sm font-medium">{{ __('orders.ui_customer_phone') }}</span><input aria-invalid="{{ $errors->has('newCustomerPhone') ? 'true' : 'false' }}" wire:model="newCustomerPhone" type="tel" inputmode="numeric" pattern="09[0-9]{8}" maxlength="10" dir="ltr" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newCustomerPhone')" /></label>
                    <label><span class="text-sm font-medium">{{ __('orders.ui_delivery_address') }}</span><input aria-invalid="{{ $errors->has('newCustomerAddress') ? 'true' : 'false' }}" wire:model="newCustomerAddress" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newCustomerAddress')" /></label>
                    <label><span class="text-sm font-medium">{{ __('common.terms.area') }}</span>@if($this->areas->count() === 1)<input type="hidden" aria-invalid="{{ $errors->has('newCustomerAreaId') ? 'true' : 'false' }}" wire:model="newCustomerAreaId"><p class="mt-1 rounded-lg bg-white p-3">{{ $this->areas->sole()->path() }}</p>@else<select aria-invalid="{{ $errors->has('newCustomerAreaId') ? 'true' : 'false' }}" wire:model="newCustomerAreaId" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_area') }}</option>@foreach($this->areas as $area)<option value="{{ $area->id }}">{{ $area->path() }}</option>@endforeach</select>@endif<x-input-error :messages="$errors->get('newCustomerAreaId')" /></label>
                    <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" aria-invalid="{{ $errors->has('newCustomerDuplicateAcknowledged') ? 'true' : 'false' }}" wire:model="newCustomerDuplicateAcknowledged" class="mt-1 rounded border-gray-300"><span>{{ __('orders.ui_acknowledge_duplicate_phone') }}</span></label>
                    <div class="sm:col-span-2"><x-orders.action type="button" wire:click="createCustomer" wire:loading.attr="disabled" wire:target="createCustomer">{{ __('orders.ui_create_and_select_customer') }}</x-orders.action></div>
                        </div>
                    </div>
                </div>
            @endcan
        @endif
    @include('livewire.orders.wizard-modals')
    <div class="sticky bottom-3 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-sm" data-wizard-navigation>
        @if($step > 1)<x-orders.action id="wizard-back" type="button" variant="secondary" wire:click="goToStep({{ $step - 1 }})" wire:loading.attr="disabled" wire:target="goToStep,nextStep,submit">{{ __('orders.wizard.back') }}</x-orders.action>@else<a href="{{ route('orders.index') }}" class="text-sm text-gray-600">{{ __('common.actions.cancel') }}</a>@endif
        @if($step < 4)<x-orders.action :id="'wizard-next-'.$step" type="button" wire:click="nextStep({{ $step }})" wire:loading.attr="disabled" wire:target="nextStep,goToStep,submit"><span wire:loading.remove wire:target="nextStep">{{ __('orders.wizard.next') }}</span><span wire:loading wire:target="nextStep">{{ __('orders.ui_saving') }}</span></x-orders.action>
        @else<x-orders.action id="wizard-create" type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"><span wire:loading.remove wire:target="submit">{{ __('orders.wizard.create') }}</span><span wire:loading wire:target="submit">{{ __('orders.ui_saving') }}</span></x-orders.action>@endif
    </div>
</form>
