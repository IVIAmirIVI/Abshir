<section class="rounded-xl border border-brand-primary/10 bg-white p-4 shadow-sm sm:p-5">
    <div class="mb-4">
        <h3 class="text-lg font-bold text-brand-primary">{{ __('orders.ui_add_shop') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('orders.ui_shop_source_help') }}</p>
    </div>

    @if($orderMutable)
    <form wire:submit="add" class="space-y-5" novalidate>
        <x-input-error :messages="$errors->all()" role="alert" />
        <fieldset>
            <legend class="mb-2 text-sm font-medium">{{ __('orders.ui_shop_source') }}</legend>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach(['REGISTERED' => 'ui_registered_shop', 'CREATE_PERMANENT' => 'ui_create_permanent_shop', 'ONE_TIME' => 'ui_one_time_shop'] as $value => $label)
                    @continue($value === 'CREATE_PERMANENT' && ! auth()->user()->can('shops.create'))
                    <label @class(['flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition', 'border-brand-primary bg-green-50' => $shopType === $value, 'border-gray-200 hover:border-brand-primary/50' => $shopType !== $value])>
                        <input type="radio" wire:model.live="shopType" value="{{ $value }}" class="text-brand-primary focus:ring-brand-primary">
                        <span class="font-medium">{{ __('orders.'.$label) }}</span>
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('shopType')" class="mt-1" />
        </fieldset>

        @if($shopType === 'REGISTERED')
            <div class="grid gap-4 sm:grid-cols-2" wire:key="registered-shop-fields">
                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium">{{ __('orders.ui_registered_shop') }}</span>
                    <input type="search" wire:model.live.debounce.250ms="shopSearch" x-on:keydown.enter.prevent="$wire.selectSoleShop($event.target.value)" placeholder="{{ __('orders.ui_search_shop') }}" class="mt-1 block w-full rounded-lg border-gray-300" autocomplete="off">
                    <select name="shop_id" wire:model.live="shopId" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500' => $errors->has('shopId'), 'border-gray-300' => ! $errors->has('shopId')])>
                        <option value="">{{ __('orders.ui_choose_registered_shop') }}</option>
                        @foreach($this->shops as $shop)
                            <option value="{{ $shop->id }}">{{ $shop->name }}{{ $shop->area ? ' — '.$shop->area->name : '' }}{{ $shop->type ? ' — '.$shop->type->name : '' }}{{ $shop->business_classification ? ' — '.\App\Support\UiLabels::label('orders.shop_classifications', $shop->business_classification) : '' }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-600">{{ __('orders.ui_search_selection_hint') }}</p>
                    @if($this->shopMatches->isEmpty())<p class="mt-1 text-sm text-amber-700">{{ __('orders.ui_no_registered_shops') }}</p>@endif
                    <x-input-error :messages="$errors->get('shopId')" class="mt-1" />
                </label>
                @if($selectedShop = $this->shops->firstWhere('id', $shopId))
                    <div class="rounded-lg bg-stone-50 p-3 text-sm sm:col-span-2">
                        <p>{{ $selectedShop->area?->name }} · {{ $selectedShop->address }}</p>
                        @if($selectedShop->phone)<p><x-ltr>{{ $selectedShop->phone }}</x-ltr></p>@endif
                    </div>
                @endif
                @if($selectedShop && !$selectedShop->business_classification)<p role="alert">{{ __('orders.wizard.missing_master_classification') }}</p>@endif
            </div>
        @elseif($shopType === 'CREATE_PERMANENT')
            @can('shops.create')
                <div class="grid gap-4 rounded-lg border border-orange-200 bg-orange-50/50 p-4 sm:grid-cols-2" wire:key="create-permanent-shop-fields">
                    <label><span class="text-sm font-medium">{{ __('common.fields.name') }}</span><input wire:model.blur="newShopName" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newShopName')" /></label>
                    <label>{{ __('orders.ui_shop_classification') }}<select wire:model="newShopClassification" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_classification') }}</option>@foreach(\App\Enums\ShopBusinessClassification::cases() as $classification)<option value="{{ $classification->value }}">{{ \App\Support\UiLabels::label('orders.shop_classifications', $classification) }}</option>@endforeach</select><x-input-error :messages="$errors->get('newShopClassification')" /></label>
                    @if($this->shopTypes->isNotEmpty())
                    <label><span class="text-sm font-medium">{{ __('orders.ui_shop_type') }}</span>@if($this->shopTypes->count() === 1)<input type="hidden" wire:model="newShopTypeId"><p class="mt-1 rounded-lg bg-white p-3">{{ $this->shopTypes->sole()->name }}</p>@else<select wire:model="newShopTypeId" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_shop_type') }}</option>@foreach($this->shopTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select>@endif<x-input-error :messages="$errors->get('newShopTypeId')" /></label>
                    @endif
                    @include('shops._type-help', ['types' => $this->shopTypes])
                    <label><span class="text-sm font-medium">{{ __('common.terms.area') }}</span>@if($this->areas->count() === 1)<input type="hidden" wire:model="newShopAreaId"><p class="mt-1 rounded-lg bg-white p-3">{{ $this->areas->sole()->path() }}</p>@else<select wire:model="newShopAreaId" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_area') }}</option>@foreach($this->areas as $area)<option value="{{ $area->id }}">{{ $area->path() }}</option>@endforeach</select>@endif<x-input-error :messages="$errors->get('newShopAreaId')" /></label>
                    <label><span class="text-sm font-medium">{{ __('orders.ui_one_phone') }}</span><input wire:model.blur="newShopPhone" type="tel" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newShopPhone')" /></label>
                    <label class="sm:col-span-2"><span class="text-sm font-medium">{{ __('orders.ui_one_address') }}</span><input wire:model.blur="newShopAddress" class="mt-1 block w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('newShopAddress')" /></label>
                    @if($this->areas->isEmpty())<p class="text-sm text-amber-800 sm:col-span-2">{{ __('orders.ui_no_inline_shop_areas') }}</p>@endif
                    <div class="sm:col-span-2"><x-primary-button :disabled="$this->areas->isEmpty()" type="button" wire:click="createPermanentShop" wire:loading.attr="disabled" wire:target="createPermanentShop">{{ __('orders.ui_create_and_select_shop') }}</x-primary-button></div>
                </div>
            @else
                <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('orders.ui_no_shop_create_permission') }}</p>
            @endcan
        @elseif($shopType === 'ONE_TIME')
            <div class="grid gap-4 sm:grid-cols-2" wire:key="one-time-shop-fields">
                <label class="block">
                    <span class="text-sm font-medium">{{ __('orders.ui_one_shop_name') }}</span>
                    <input name="shop_name" wire:model.blur="shopName" dir="auto" @class(['mt-1 block w-full rounded-lg shadow-sm', 'border-red-500' => $errors->has('shopName'), 'border-gray-300' => ! $errors->has('shopName')])>
                    <x-input-error :messages="$errors->get('shopName')" class="mt-1" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">{{ __('orders.ui_shop_classification') }}</span>
                    <select name="business_classification" wire:model.blur="businessClassification" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        <option value="">{{ __('orders.ui_choose_classification') }}</option>
                        @foreach(\App\Enums\ShopBusinessClassification::cases() as $classification)<option value="{{ $classification->value }}">{{ \App\Support\UiLabels::label('orders.shop_classifications', $classification) }}</option>@endforeach
                    </select>
                    <x-input-error :messages="$errors->get('businessClassification')" class="mt-1" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">{{ __('orders.ui_one_phone') }}</span>
                    <input name="shop_phone" wire:model.blur="shopPhone" type="tel" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                    <x-input-error :messages="$errors->get('shopPhone')" class="mt-1" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">{{ __('orders.ui_one_address') }}</span>
                    <input name="shop_address" wire:model.blur="shopAddress" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                    <x-input-error :messages="$errors->get('shopAddress')" class="mt-1" />
                </label>
                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium">{{ __('orders.ui_shop_area') }}</span>
                    @if($this->areas->count() === 1)
                        <input type="hidden" wire:model="originAreaId"><p class="mt-1 rounded-lg bg-white p-3">{{ $this->areas->sole()->path() }}</p>
                    @else
                        <select wire:model="originAreaId" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_area') }}</option>@foreach($this->areas as $area)<option value="{{ $area->id }}">{{ $area->path() }}</option>@endforeach</select>
                    @endif
                    <x-input-error :messages="$errors->get('originAreaId')" class="mt-1" />
                </label>
            </div>
        @else
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('orders.ui_choose_shop_source_first') }}</p>
        @endif

        <label class="block">
            <span class="text-sm font-medium">{{ __('common.fields.notes') }}</span>
            <textarea name="notes" wire:model.blur="notes" rows="2" dir="auto" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm"></textarea>
            <x-input-error :messages="$errors->get('notes')" class="mt-1" />
        </label>

        @if($shopType !== 'CREATE_PERMANENT')<x-primary-button wire:loading.attr="disabled" wire:target="add" :disabled="$shopType === ''" class="w-full justify-center sm:w-auto disabled:cursor-not-allowed disabled:opacity-50">
            <span wire:loading.remove wire:target="add">{{ __('orders.ui_add_shop') }}</span>
            <span wire:loading wire:target="add">{{ __('orders.ui_saving') }}</span>
        </x-primary-button>@endif
    </form>
    @endif
</section>
