<form wire:submit="submit" data-order-create-select2 class="space-y-6" novalidate>
    <x-input-error :messages="$errors->all()" role="alert" />
    @if(session('order-create-status'))<p class="rounded-lg bg-green-50 p-3 text-sm text-green-900">{{ session('order-create-status') }}</p>@endif
    <div class="rounded-xl border border-brand-primary/10 bg-white p-4 shadow-sm sm:p-6">
        <div class="mb-5">
            <h3 class="text-lg font-bold text-brand-primary">{{ __('orders.ui_customer_delivery_step') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('orders.ui_customer_delivery_help') }}</p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">{{ __('common.terms.branch') }}</span>
                @if($this->branches->count() === 1)
                    <input type="hidden" wire:model="branchId"><p class="mt-1 rounded-lg bg-stone-50 p-3 font-medium">{{ $this->branches->sole()->name }}</p>
                @else
                <select name="branch_id" wire:model.live="branchId" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500 ring-red-200' => $errors->has('branchId'), 'border-gray-300' => ! $errors->has('branchId')])>
                    <option value="">{{ __('orders.ui_choose_branch') }}</option>
                    @foreach($this->branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                </select>
                @endif
                <x-input-error :messages="$errors->get('branchId')" class="mt-1" />
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('common.terms.customer') }}</span>
                <select name="customer_id" wire:model.live="customerId" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500 ring-red-200' => $errors->has('customerId'), 'border-gray-300' => ! $errors->has('customerId')])>
                    <option value="">{{ __('orders.ui_choose_customer') }}</option>
                    @foreach($this->customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}@if($phone = $customer->phones->sortByDesc('is_primary')->first()) — {{ "\u{2066}".$phone->phone."\u{2069}" }}@endif @if($address = $customer->addresses->first()) — {{ $address->area->name }} · {{ $address->address_line_1 }}@endif</option>@endforeach
                </select>
                <p class="mt-1 text-xs text-gray-600">{{ __('orders.ui_search_selection_hint') }}</p>
                <x-input-error :messages="$errors->get('customerId')" class="mt-1" />
                @can('customers.create')<button type="button" wire:click="toggleCustomerCreate" class="mt-2 text-sm font-semibold text-brand-primary hover:underline">{{ __('orders.ui_add_new_customer') }}</button>@endcan
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_customer_phone') }}</span>
                @if($this->phones->count() === 1)
                    <input type="hidden" wire:model="customerPhoneId"><p class="mt-1 rounded-lg bg-stone-50 p-3" dir="ltr">{{ $this->phones->sole()->phone }}</p>
                @else
                <select dir="ltr" name="customer_phone_id" wire:model.blur="customerPhoneId" @disabled(! $customerId) @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500 ring-red-200' => $errors->has('customerPhoneId'), 'border-gray-300' => ! $errors->has('customerPhoneId')])>
                    <option value="">{{ $customerId ? __('orders.ui_choose_phone') : __('orders.ui_choose_customer_first') }}</option>
                    @foreach($this->phones as $phone)<option value="{{ $phone->id }}">{{ $phone->phone }}</option>@endforeach
                </select>
                @endif
                <x-input-error :messages="$errors->get('customerPhoneId')" class="mt-1" />
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_delivery_address') }}</span>
                @if($this->addresses->count() === 1)
                    @php($singleAddress = $this->addresses->sole())
                    <input type="hidden" wire:model="customerAddressId"><p class="mt-1 rounded-lg bg-stone-50 p-3">{{ $singleAddress->address_line_1 }} — {{ $singleAddress->area->name }}</p>
                @else
                <select name="customer_address_id" wire:model.blur="customerAddressId" @disabled(! $customerId || ! $branchId) @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500 ring-red-200' => $errors->has('customerAddressId'), 'border-gray-300' => ! $errors->has('customerAddressId')])>
                    <option value="">{{ ($customerId && $branchId) ? __('orders.ui_choose_address') : __('orders.ui_choose_customer_branch_first') }}</option>
                    @foreach($this->addresses as $address)
                        <option value="{{ $address->id }}">{{ $address->address_line_1 }} — {{ $address->area->name }}{{ $address->area->parent ? ' ('.$address->area->parent->name.')' : '' }}</option>
                    @endforeach
                </select>
                @endif
                @if($customerId && $branchId && $this->addresses->isEmpty())<p class="mt-1 text-sm text-amber-700">{{ __('orders.ui_no_customer_addresses') }}</p>@endif
                <x-input-error :messages="$errors->get('customerAddressId')" class="mt-1" />
            </label>
        </div>

        @if($showCustomerCreate)
            @can('customers.create')
                <div wire:key="create-customer-modal" role="dialog" aria-modal="true" aria-labelledby="create-customer-title" data-customer-create-modal x-data x-trap.inert.noscroll="true" x-on:keydown.escape.stop="$wire.closeCustomerCreate()" x-on:keydown.enter="if ($event.target.matches('input:not(.select2-search__field)')) { $event.preventDefault(); $wire.createCustomer(); }" class="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6">
                    <button type="button" wire:click="closeCustomerCreate" class="fixed inset-0 bg-gray-900/50" tabindex="-1" aria-label="{{ __('common.actions.cancel') }}"></button>
                    <div class="relative mx-auto w-full max-w-2xl rounded-xl bg-white p-5 shadow-xl sm:p-6">
                        <div class="mb-5 flex items-center justify-between gap-3"><h4 id="create-customer-title" class="text-lg font-semibold text-brand-primary">{{ __('orders.ui_add_new_customer') }}</h4><button type="button" wire:click="closeCustomerCreate" class="rounded px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">{{ __('common.actions.cancel') }}</button></div>
                        <div class="grid gap-4 sm:grid-cols-2">
                    <label><span class="text-sm font-medium">{{ __('common.fields.name') }}</span><input wire:model="newCustomerName" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newCustomerName')" /></label>
                    <label><span class="text-sm font-medium">{{ __('orders.ui_customer_phone') }}</span><input wire:model="newCustomerPhone" dir="ltr" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newCustomerPhone')" /></label>
                    <label><span class="text-sm font-medium">{{ __('orders.ui_delivery_address') }}</span><input wire:model="newCustomerAddress" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newCustomerAddress')" /></label>
                    <label><span class="text-sm font-medium">{{ __('common.terms.area') }}</span>@if($this->areas->count() === 1)<input type="hidden" wire:model="newCustomerAreaId"><p class="mt-1 rounded-lg bg-white p-3">{{ $this->areas->sole()->path() }}</p>@else<select wire:model="newCustomerAreaId" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_area') }}</option>@foreach($this->areas as $area)<option value="{{ $area->id }}">{{ $area->path() }}</option>@endforeach</select>@endif<x-input-error :messages="$errors->get('newCustomerAreaId')" /></label>
                    <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="newCustomerDuplicateAcknowledged" class="mt-1 rounded border-gray-300"><span>{{ __('orders.ui_acknowledge_duplicate_phone') }}</span></label>
                    <div class="sm:col-span-2"><x-primary-button type="button" wire:click="createCustomer" wire:loading.attr="disabled" wire:target="createCustomer">{{ __('orders.ui_create_and_select_customer') }}</x-primary-button></div>
                        </div>
                    </div>
                </div>
            @endcan
        @endif
    </div>

    <div class="rounded-xl border border-brand-primary/10 bg-white p-4 shadow-sm sm:p-6">
        <h3 class="mb-5 text-lg font-bold text-brand-primary">{{ __('orders.ui_order_details_step') }}</h3>
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_source_label') }}</span>
                <select name="source" wire:model.blur="source" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500' => $errors->has('source'), 'border-gray-300' => ! $errors->has('source')])>
                    <option value="">{{ __('orders.ui_choose_source') }}</option>
                    @foreach($sources as $choice)<option value="{{ $choice->value }}">{{ \App\Support\UiLabels::label('orders.sources', $choice) }}</option>@endforeach
                </select>
                <x-input-error :messages="$errors->get('source')" class="mt-1" />
            </label>

            <label class="block">
                <span class="text-sm font-medium">{{ __('orders.ui_entry_mode') }}</span>
                <select name="entry_mode" wire:model.live="entryMode" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                    @foreach($entryModes as $mode)<option value="{{ $mode->value }}">{{ \App\Support\UiLabels::label('orders.entry_modes', $mode) }}</option>@endforeach
                </select>
                <x-input-error :messages="$errors->get('entryMode')" class="mt-1" />
            </label>

            @if($entryMode === 'HISTORICAL_MANUAL')
                <label class="block sm:col-span-2" wire:key="historical-order-time">
                    <span class="text-sm font-medium">{{ __('orders.ui_historical_time') }}</span>
                    <input name="ordered_at" type="datetime-local" wire:model.blur="orderedAt" max="{{ now()->format('Y-m-d\TH:i') }}" dir="ltr" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500' => $errors->has('orderedAt'), 'border-gray-300' => ! $errors->has('orderedAt')])>
                    <p class="mt-1 text-xs text-gray-600">{{ __('orders.ui_entry_time_help') }}</p>
                    <x-input-error :messages="$errors->get('orderedAt')" class="mt-1" />
                </label>
            @endif

            <label class="block sm:col-span-2">
                <span class="text-sm font-medium">{{ __('common.fields.notes') }}</span>
                <textarea name="notes" wire:model.blur="notes" rows="3" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm"></textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </label>
        </div>
    </div>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ route('orders.index') }}" class="rounded-lg px-4 py-2 text-center text-sm font-medium text-gray-700 hover:bg-white">{{ __('common.actions.cancel') }}</a>
        <x-primary-button wire:loading.attr="disabled" wire:target="submit" class="justify-center disabled:cursor-wait disabled:opacity-60">
            <span wire:loading.remove wire:target="submit">{{ __('orders.ui_create_shops') }}</span>
            <span wire:loading wire:target="submit">{{ __('orders.ui_saving') }}</span>
        </x-primary-button>
    </div>
</form>
