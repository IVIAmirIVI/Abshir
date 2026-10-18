<x-orders.section id="wizard-shops">
    <h3 id="wizard-shops-title" class="text-lg font-bold text-brand-primary">{{ __('orders.wizard.steps.shops') }}</h3>
    <div class="grid gap-4 sm:grid-cols-2">
        <label><span class="text-sm font-medium">{{ __('orders.ui_shop_source') }}</span><select aria-invalid="{{ $errors->has('shopType') ? 'true' : 'false' }}" wire:model.live="shopType" class="mt-1 w-full rounded-lg border-gray-300"><option value="REGISTERED">{{ __('orders.ui_registered_shop') }}</option><option value="ONE_TIME">{{ __('orders.ui_one_time_shop') }}</option></select></label>
        @if($shopType === 'REGISTERED')
            <div><label class="text-sm font-medium">{{ __('orders.ui_registered_shop') }}</label>
            <x-orders.wizard-search model="shopSearch" choose="selectShop" sole="selectSoleShop" :query="$shopSearch" :label="__('orders.wizard.search_shop')">
                @foreach($this->shopMatches as $shop)<button type="button" role="option" aria-selected="{{ (string) $shop->id === $shopId ? 'true' : 'false' }}" wire:click="selectShop({{ $shop->id }})" x-on:click="open = false" class="block w-full rounded-lg p-3 text-start text-sm hover:bg-stone-100 focus:bg-stone-100">{{ $shop->name }} — {{ $shop->area->name }} @if($shop->type) · {{ $shop->type->name }} @endif</button>@endforeach
                @if($this->shopMatches->isEmpty())<p class="p-3 text-sm text-gray-500">{{ __('orders.wizard.no_results') }}</p>@endif
            </x-orders.wizard-search>
            @can('shops.create')<button type="button" wire:click="openShopCreate" class="mt-2 text-sm font-semibold text-brand-primary">{{ __('orders.wizard.add_shop') }}</button>@endcan</div>
        @else
            <label>{{ __('orders.ui_one_shop_name') }}<input aria-invalid="{{ $errors->has('shopName') ? 'true' : 'false' }}" wire:model="shopName" dir="auto" class="mt-1 w-full rounded-lg border-gray-300"></label>
            <label>{{ __('orders.ui_one_phone') }}<input aria-invalid="{{ $errors->has('shopPhone') ? 'true' : 'false' }}" wire:model="shopPhone" type="tel" inputmode="numeric" dir="ltr" class="mt-1 w-full rounded-lg border-gray-300"></label>
            <label>{{ __('orders.ui_one_address') }}<input aria-invalid="{{ $errors->has('shopAddress') ? 'true' : 'false' }}" wire:model="shopAddress" dir="auto" class="mt-1 w-full rounded-lg border-gray-300"></label>
            <label>{{ __('orders.ui_shop_area') }}<select aria-invalid="{{ $errors->has('originAreaId') ? 'true' : 'false' }}" wire:model="originAreaId" class="mt-1 w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_area') }}</option>@foreach($this->areas as $area)<option value="{{ $area->id }}">{{ $area->path() }}</option>@endforeach</select></label>
        @endif
        @if($shopType === 'REGISTERED' && $this->knownShopClassification)
            <p class="text-sm">{{ __('orders.ui_shop_classification') }}: {{ \App\Support\UiLabels::label('orders.shop_classifications', $this->knownShopClassification) }}</p>
        @endif
        @if($shopType === 'REGISTERED' && $shopId && !$this->knownShopClassification)
            <div role="alert" class="rounded-lg bg-amber-50 p-3 text-sm">{{ __('orders.wizard.missing_master_classification') }}
                @can('shops.update')<a href="{{ route('shops.edit', $shopId) }}" target="_blank" rel="noopener" class="underline">{{ __('common.actions.edit') }}</a>@endcan
                <button type="button" wire:click="selectShop({{ $shopId }})" class="underline">{{ __('orders.wizard.recalculate') }}</button>
            </div>
        @endif
        @if($shopType === 'ONE_TIME')
            <label>{{ __('orders.ui_shop_classification') }}<select aria-invalid="{{ $errors->has('businessClassification') ? 'true' : 'false' }}" wire:model="businessClassification" class="mt-1 w-full rounded-lg border-gray-300"><option value="">{{ __('orders.wizard.choose') }}</option>@foreach(\App\Enums\ShopBusinessClassification::cases() as $class)<option value="{{ $class->value }}">{{ \App\Support\UiLabels::label('orders.shop_classifications', $class) }}</option>@endforeach</select></label>
        @endif
    </div>
    @php($pendingShop = ($shopType === 'REGISTERED' && filled($shopId)) || ($shopType === 'ONE_TIME' && filled($shopName)))
    <div @class(['wizard-shop-addition rounded-xl p-3', 'border border-amber-200 bg-amber-50' => $pendingShop || $errors->has('groups')]) data-wizard-shop-addition>
        <p id="wizard-shop-add-hint" @class(['mb-3 min-h-12 text-sm font-medium text-amber-900', 'invisible' => !$pendingShop])>{{ __('orders.wizard.shop_selected_not_added') }}</p>
        <x-orders.action id="wizard-add-shop" type="button" wire:click="addDraftShop" wire:loading.attr="disabled" wire:target="addDraftShop" aria-describedby="{{ trim(($pendingShop ? 'wizard-shop-add-hint ' : '').($errors->has('groups') ? 'wizard-shop-add-error' : '')) }}">{{ __('orders.wizard.add_group') }}</x-orders.action>
        @error('groups')<p id="wizard-shop-add-error" role="alert" class="mt-3 text-sm font-medium text-red-700">{{ $message }}</p>@enderror
    </div>
    <p class="text-xs text-gray-500">{{ __('orders.ui_search_selection_hint') }}</p>
    @foreach($groups as $key => $group)
        <article wire:key="draft-shop-{{ $key }}" class="min-w-0 rounded-xl border p-4 {{ $activeGroup === $key ? 'border-brand-primary' : 'border-gray-200' }}">
            <div class="flex flex-wrap items-center justify-between gap-2"><button type="button" wire:click="editGroup('{{ $key }}')" @disabled(!in_array($key, $this->eligibleGroupKeys, true)) class="font-semibold text-brand-primary">{{ $group['label'] }} — {{ $group['area_label'] }}</button><button type="button" wire:click="removeDraftShop('{{ $key }}')" class="text-sm text-red-700">{{ __('orders.wizard.remove_shop') }}</button></div>
            <p class="mt-3 text-xs leading-6 text-gray-500">{{ __('order_design.draft_prices') }}</p><ul class="mt-3 space-y-2">@foreach($group['items'] as $index => $item)<li wire:key="draft-item-{{ $key }}-{{ $index }}" class="flex flex-wrap items-center justify-between gap-2 border-t pt-2 text-sm"><span>{{ $item['label'] }} · <x-decimal :value="$item['quantity']" /> {{ $item['unit_label'] }}<x-orders.draft-totals /></span><button type="button" wire:click="removeDraftItem('{{ $key }}', {{ $index }})" class="text-red-700">{{ __('common.actions.remove') }}</button></li>@endforeach</ul><div class="mt-3 border-t border-stone-200 pt-3"><x-orders.draft-totals shop /></div>
            @if(!$group['items'])<p class="mt-3 text-sm text-amber-700">{{ __('orders.wizard.empty_items') }}</p>@endif
            @if(!in_array($key, $this->eligibleGroupKeys, true))<p role="alert" class="mt-2 text-sm text-amber-800">{{ __('domain.the_shop_is_outside_the_order_branch') }}</p>@endif
            @if($activeGroup === $key && in_array($key, $this->eligibleGroupKeys, true))
                <fieldset wire:key="draft-item-editor-{{ $key }}" x-bind:disabled="pendingEditorActions > 0" class="mt-4 grid min-w-0 gap-3 rounded-lg bg-stone-50 p-3 sm:grid-cols-2">
                    <label>{{ __('orders.ui_item_type') }}<select aria-invalid="{{ $errors->has('itemType') ? 'true' : 'false' }}" wire:model.live="itemType" class="mt-1 w-full rounded-lg border-gray-300"><option value="CATALOG_ITEM">{{ __('orders.ui_catalog_item') }}</option><option value="ONE_TIME_ITEM">{{ __('orders.ui_one_item') }}</option></select></label>
                    @if($itemType === 'CATALOG_ITEM')
                        @if(\Illuminate\Support\Facades\Schema::hasTable('product_delivery_policies'))<label class="flex items-center gap-2"><input type="checkbox" wire:model.live="specialProductsOnly">{{ __('special.title') }}</label>@endif
                        <div><label>{{ __('common.terms.product') }}</label><x-orders.wizard-search model="productSearch" choose="selectProduct" sole="selectSoleProduct" :query="$productSearch" :label="__('orders.wizard.search_product')">
                            @foreach($this->productMatches as $product)<button type="button" role="option" aria-selected="{{ (string) $product->id === $productId ? 'true' : 'false' }}" wire:click="selectProduct({{ $product->id }})" x-on:click="open = false" class="block w-full rounded-lg p-3 text-start text-sm hover:bg-stone-100 focus:bg-stone-100">{{ $product->name }} @if($product->sku) · <bdi>{{ $product->sku }}</bdi>@endif — {{ $product->defaultUnit->name }}</button>@endforeach
                            @if($this->productMatches->isEmpty())<p class="p-3 text-sm text-gray-500">{{ __('orders.wizard.no_results') }}</p>@endif
                        </x-orders.wizard-search>@can('products.create')<button type="button" wire:click="openProductCreate" class="mt-2 text-sm font-semibold text-brand-primary">{{ __('orders.ui_add_new_product') }}</button>@endcan</div>
                    @else<label>{{ __('orders.ui_one_item_name') }}<input aria-invalid="{{ $errors->has('itemName') ? 'true' : 'false' }}" wire:model="itemName" dir="auto" class="mt-1 w-full rounded-lg border-gray-300"></label>@endif
                    <label>{{ __('common.terms.unit') }}<select aria-invalid="{{ $errors->has('unitId') ? 'true' : 'false' }}" wire:model="unitId" class="mt-1 w-full rounded-lg border-gray-300"><option value="">{{ __('orders.wizard.choose') }}</option>@foreach($this->units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach</select></label>
                    <label>{{ __('orders.ui_quantity') }}<input aria-invalid="{{ $errors->has('quantity') ? 'true' : 'false' }}" wire:model="quantity" type="text" inputmode="decimal" dir="ltr" class="mt-1 w-full rounded-lg border-gray-300"></label>
                    <button type="button" wire:click="addDraftItem" class="rounded-lg border border-brand-primary px-4 py-2 text-sm font-semibold text-brand-primary sm:col-span-2">{{ __('orders.wizard.add_item') }}</button>
                </fieldset>
            @endif
        </article>
    @endforeach
    @include('livewire.orders.wizard-special-addons')
    @if(!$groups)<p class="text-sm text-gray-500">{{ __('orders.wizard.empty_shops') }}</p>@endif
</x-orders.section>
