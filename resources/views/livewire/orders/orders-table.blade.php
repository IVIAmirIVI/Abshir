<div class="min-w-0 rounded-xl border border-stone-200 bg-white shadow-sm" data-orders-table x-data="{ filtersOpen: false, sortOpen: false }" @keydown.escape.window="filtersOpen = false; sortOpen = false">
    @php($sortLabels = ['order_number' => __('orders.table.reference'), 'customer_name_snapshot' => __('common.terms.customer'), 'operational_status' => __('orders.table.status'), 'ordered_at' => __('orders.table.date')])
    @php($hasFilters = $branchId !== '' || $status !== '' || $pricingStatus !== '' || $from !== '' || $to !== '')
    <div class="relative flex flex-wrap items-center gap-2 border-b border-stone-200 bg-stone-50/70 px-3 py-3 sm:gap-3 sm:px-4">
        <label class="min-w-0 basis-full sm:basis-auto sm:flex-1 lg:max-w-sm">
            <span class="sr-only">{{ __('common.actions.search') }}</span>
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('orders.table.search_placeholder') }}" dir="auto" class="block min-h-11 w-full min-w-0 rounded-lg border-stone-300 bg-white text-sm placeholder:text-gray-500 focus:border-brand-primary focus:ring-brand-primary">
        </label>
        <div class="relative" @click.outside="filtersOpen = false">
            <button type="button" @click="sortOpen = false; filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="orders-index-filters" aria-label="{{ __('orders.table.filters') }}" @class(['relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary', 'border-brand-primary bg-brand-primary text-white' => $hasFilters, 'border-stone-300 bg-white text-brand-primary hover:bg-stone-100' => !$hasFilters])>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18l-7 8v7l-4 2v-9L3 4Z" /></svg>
                @if($hasFilters)<span class="absolute -top-1 end-0 h-2 w-2 rounded-full bg-brand-accent"><span class="sr-only">{{ __('orders.table.active_filters') }}</span></span>@endif
            </button>
            <div id="orders-index-filters" x-cloak x-show="filtersOpen" x-transition.opacity class="absolute start-0 top-full z-30 mt-2 w-[min(20rem,calc(100vw-3.5rem))] space-y-3 rounded-lg border border-stone-200 bg-white p-4 shadow-lg sm:start-auto sm:end-0">
                <p class="border-b border-stone-100 pb-2 text-sm font-semibold text-brand-primary">{{ __('orders.table.filters') }}</p>
                <label class="block"><span class="text-xs text-gray-600">{{ __('common.terms.branch') }}</span><select wire:model.live="branchId" class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-brand-primary focus:ring-brand-primary"><option value="">{{ __('orders.ui_all_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <label class="block"><span class="text-xs text-gray-600">{{ __('orders.table.status') }}</span><select wire:model.live="status" class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-brand-primary focus:ring-brand-primary"><option value="">{{ __('orders.ui_all_statuses') }}</option>@foreach($statuses as $statusOption)<option value="{{ $statusOption->value }}">{{ \App\Support\UiLabels::label('orders.statuses', $statusOption) }}</option>@endforeach</select></label>
                <label class="block"><span class="text-xs text-gray-600">{{ __('orders.table.pricing') }}</span><select wire:model.live="pricingStatus" class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-brand-primary focus:ring-brand-primary"><option value="">{{ __('orders.ui_all_pricing') }}</option>@foreach($pricingStatuses as $pricingOption)<option value="{{ $pricingOption->value }}">{{ \App\Support\UiLabels::label('orders.pricing_statuses', $pricingOption) }}</option>@endforeach</select></label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(['from', 'to'] as $dateField)
                        <div class="min-w-0">
                            <label for="orders-{{ $dateField }}-date" class="text-xs text-gray-600">{{ __('orders.table.'.$dateField) }}</label>
                            <div wire:ignore class="relative mt-1">
                                <input id="orders-{{ $dateField }}-date" type="text" readonly autocomplete="off" data-order-date-filter="{{ $dateField }}" wire:model.live="{{ $dateField }}" placeholder="YYYY-MM-DD" dir="ltr" class="block min-h-11 w-full min-w-0 cursor-pointer rounded-md border-stone-300 py-2 pl-2 pr-8 text-left text-xs tabular-nums focus:border-brand-primary focus:ring-brand-primary">
                                <button type="button" data-clear-order-date aria-label="{{ __('orders.table.clear_date', ['field' => __('orders.table.'.$dateField)]) }}" class="absolute inset-y-0 right-0 inline-flex min-h-11 w-8 items-center justify-center rounded text-gray-500 hover:text-brand-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary"><span aria-hidden="true">×</span></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="resetFilters" wire:loading.attr="disabled" class="min-h-11 w-full rounded-md border border-stone-300 text-sm font-medium text-brand-primary hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary disabled:opacity-50">{{ __('orders.table.reset') }}</button>
            </div>
        </div>
        <div class="lg:hidden" @click.outside="sortOpen = false">
            <button type="button" @click="filtersOpen = false; sortOpen = !sortOpen" :aria-expanded="sortOpen" aria-controls="orders-index-sort" aria-label="{{ __('orders.table.sort_orders') }}" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-stone-300 bg-white px-2.5 text-xs font-medium text-brand-primary hover:bg-stone-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 4v16m-3-3 3 3 3-3M16 20V4m-3 3 3-3 3 3" /></svg>{{ __('orders.table.sort_button') }}
            </button>
            <div id="orders-index-sort" x-cloak x-show="sortOpen" x-transition.opacity class="absolute inset-x-3 top-full z-30 mt-2 grid grid-cols-2 gap-3 rounded-lg border border-stone-200 bg-white p-3 shadow-lg sm:inset-x-auto sm:start-3 sm:w-80">
                <label class="min-w-0"><span class="text-xs text-gray-600">{{ __('orders.table.sort') }}</span><select wire:model.live="sortField" class="mt-1 block min-h-11 w-full rounded-md border-stone-300 text-sm focus:border-brand-primary focus:ring-brand-primary">@foreach($sortLabels as $field => $label)<option value="{{ $field }}">{{ $label }}</option>@endforeach</select></label>
                <label class="min-w-0"><span class="text-xs text-gray-600">{{ __('orders.table.direction') }}</span><select wire:model.live="sortDirection" class="mt-1 block min-h-11 w-full rounded-md border-stone-300 text-sm focus:border-brand-primary focus:ring-brand-primary"><option value="desc">{{ __('orders.table.descending') }}</option><option value="asc">{{ __('orders.table.ascending') }}</option></select></label>
            </div>
        </div>
        <label class="flex items-center gap-2"><span class="sr-only sm:not-sr-only sm:text-xs sm:text-gray-600">{{ __('orders.table.per_page') }}</span><select wire:model.live="perPage" class="min-h-11 rounded-lg border-stone-300 py-2 text-sm focus:border-brand-primary focus:ring-brand-primary">@foreach([10,25,50] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></label>
        <p role="status" aria-live="polite" class="text-xs text-gray-600">
            <span wire:loading>{{ __('orders.table.loading') }}</span>
            <span wire:loading.remove>{{ __('orders.table.results', ['count' => $orders->total()]) }}@if(count($checkboxValues))<span class="ms-2 font-medium text-brand-primary">· {{ __('orders.table.selected', ['count' => count($checkboxValues)]) }}</span>@endif</span>
        </p>
        @if($hasFilters || $search !== '')<button type="button" wire:click="resetFilters" wire:loading.attr="disabled" class="min-h-11 rounded-lg px-2 text-xs font-medium text-brand-primary underline decoration-stone-300 underline-offset-4 hover:bg-stone-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary disabled:opacity-50">{{ __('orders.table.reset') }}</button>@endif
    </div>
    <x-input-error :messages="$errors->all()" class="px-4 py-2" role="alert" />

    <div wire:loading.class="opacity-50" class="min-w-0 transition-opacity">
        @if($orders->isEmpty())
            <div class="p-8 text-center text-sm">
                <p class="font-medium text-gray-600">{{ $hasOrders ? __('orders.table.no_matches') : __('orders.table.no_orders') }}</p>
                @if($hasOrders)<button type="button" wire:click="resetFilters" wire:loading.attr="disabled" class="mt-3 min-h-11 rounded-lg px-4 py-2 font-semibold text-brand-primary hover:bg-stone-50">{{ __('orders.table.reset') }}</button>@endif
            </div>
        @else
            <div class="hidden overflow-x-auto lg:block">
                <table class="w-full text-start text-sm">
                    <caption class="sr-only">{{ __('orders.ui_title') }}</caption>
                    <thead class="border-b border-stone-200 bg-stone-50/50 text-xs text-gray-600">
                        <tr>
                            <th scope="col" class="w-11 px-1"><label class="inline-flex h-9 w-9 cursor-pointer items-center justify-center"><input type="checkbox" wire:model="checkboxAll" wire:click="selectCheckboxAll" wire:loading.attr="disabled" aria-label="{{ __('orders.table.select_page') }}" class="h-4 w-4 rounded border-stone-300 text-brand-primary focus:ring-brand-primary"></label></th>
                            @foreach(['order_number', 'customer_name_snapshot'] as $field)
                                <th scope="col" aria-sort="{{ $sortField === $field ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}" @class(['px-2 py-1.5 text-start', 'w-44' => $field === 'order_number', 'w-1/5' => $field === 'customer_name_snapshot'])><button type="button" wire:click="sortBy('{{ $field }}')" wire:loading.attr="disabled" class="flex min-h-9 w-full items-center gap-2 whitespace-nowrap text-start focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ $sortLabels[$field] }}<span class="text-gray-400" aria-hidden="true">{{ $sortField === $field ? ($sortDirection === 'asc' ? '↑' : '↓') : '↕' }}</span></button></th>
                            @endforeach
                            <th scope="col" class="px-2 py-1.5 text-start">{{ __('common.terms.branch') }}</th>
                            <th scope="col" aria-sort="{{ $sortField === 'operational_status' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}" class="px-2 py-1.5 text-start"><button type="button" wire:click="sortBy('operational_status')" wire:loading.attr="disabled" class="flex min-h-9 w-full items-center gap-2 whitespace-nowrap text-start focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('orders.table.status') }}<span class="text-gray-400" aria-hidden="true">{{ $sortField === 'operational_status' ? ($sortDirection === 'asc' ? '↑' : '↓') : '↕' }}</span></button></th>
                            <th scope="col" class="px-2 py-1.5 text-start">{{ __('orders.table.pricing') }}</th>
                            <th scope="col" aria-sort="{{ $sortField === 'ordered_at' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}" class="w-36 px-2 py-1.5 text-start"><button type="button" wire:click="sortBy('ordered_at')" wire:loading.attr="disabled" class="flex min-h-9 w-full items-center gap-2 whitespace-nowrap text-start focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('orders.table.date') }}<span class="text-gray-400" aria-hidden="true">{{ $sortField === 'ordered_at' ? ($sortDirection === 'asc' ? '↑' : '↓') : '↕' }}</span></button></th>
                            <th scope="col" class="w-11 px-1"><span class="sr-only">{{ __('orders.table.view_order') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 text-gray-700">
                        @foreach($orders as $order)
                            @php($selected = in_array((string) $order->id, $checkboxValues, true))
                            <tr wire:key="order-table-{{ $order->id }}" @class(['hover:bg-stone-50', 'bg-green-50' => $selected])>
                                <td class="px-1 py-1.5"><label class="inline-flex h-9 w-9 cursor-pointer items-center justify-center"><input type="checkbox" wire:model.live="checkboxValues" value="{{ $order->id }}" wire:loading.attr="disabled" aria-label="{{ __('orders.table.select_order', ['reference' => $order->order_number]) }}" class="h-4 w-4 rounded border-stone-300 text-brand-primary focus:ring-brand-primary"></label></td>
                                <td class="whitespace-nowrap px-2 py-1.5"><a href="{{ route('orders.show', $order) }}" class="font-mono text-xs font-semibold text-brand-primary hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary"><bdi dir="ltr">{{ $order->order_number }}</bdi></a></td>
                                <td class="max-w-xs break-words px-2 py-1.5"><p class="text-sm font-medium leading-5">{{ $order->customer_name_snapshot }}</p><p class="text-xs leading-4 text-gray-500"><bdi dir="ltr">{{ $order->customer_phone_snapshot }}</bdi></p></td>
                                <td class="max-w-40 break-words px-2 py-1.5 text-xs">{{ $order->branch->name }}</td>
                                <td class="px-2 py-1.5">@include('livewire.orders.table-status')</td>
                                <td class="px-2 py-1.5 text-xs text-gray-500">{{ \App\Support\UiLabels::label('orders.pricing_statuses', $order->pricing_status) }}</td>
                                <td class="whitespace-nowrap px-2 py-1.5 text-xs tabular-nums text-gray-500"><bdi dir="ltr">{{ $order->ordered_at->format('Y-m-d H:i') }}</bdi></td>
                                <td class="px-1 py-1.5">
                                    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false" class="relative">
                                        <button type="button" @click="open = !open" :aria-expanded="open" aria-controls="order-actions-{{ $order->id }}" aria-label="{{ __('orders.table.actions', ['reference' => $order->order_number]) }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-stone-100 hover:text-brand-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>
                                        <div id="order-actions-{{ $order->id }}" x-cloak x-show="open" class="absolute end-full top-0 z-20 me-1 w-36 rounded-lg border border-stone-200 bg-white p-1 shadow-lg"><a href="{{ route('orders.show', $order) }}" class="block rounded-md px-3 py-2.5 text-xs font-medium text-brand-primary hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('orders.table.view_order') }}</a></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-stone-100 lg:hidden">
                @foreach($orders as $order)
                    <article wire:key="order-card-{{ $order->id }}" @class(['min-w-0 space-y-2 px-3 py-3', 'bg-green-50' => in_array((string) $order->id, $checkboxValues, true)])>
                        <div class="flex min-w-0 items-center gap-2">
                            <label class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center"><input type="checkbox" wire:model.live="checkboxValues" value="{{ $order->id }}" wire:loading.attr="disabled" aria-label="{{ __('orders.table.select_order', ['reference' => $order->order_number]) }}" class="h-4 w-4 rounded border-stone-300 text-brand-primary focus:ring-brand-primary"></label>
                            <a href="{{ route('orders.show', $order) }}" class="min-w-0 break-words font-mono text-xs font-semibold text-brand-primary hover:underline"><bdi dir="ltr">{{ $order->order_number }}</bdi></a>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2"><p class="min-w-0 break-words text-sm font-medium">{{ $order->customer_name_snapshot }}</p>@include('livewire.orders.table-status')</div>
                        <p class="text-xs text-gray-500"><bdi dir="ltr">{{ $order->customer_phone_snapshot }}</bdi></p>
                        <p class="break-words text-xs text-gray-500">{{ $order->branch->name }} · {{ __('orders.ui_pricing_label') }} {{ \App\Support\UiLabels::label('orders.pricing_statuses', $order->pricing_status) }}</p>
                        <div class="flex flex-wrap items-center justify-between gap-2"><time class="text-xs tabular-nums text-gray-500" datetime="{{ $order->ordered_at->toIso8601String() }}"><bdi dir="ltr">{{ $order->ordered_at->format('Y-m-d H:i') }}</bdi></time><a href="{{ route('orders.show', $order) }}" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-brand-primary hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('orders.table.view_order') }}</a></div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
    {{ $orders->links('livewire.orders.table-pagination') }}
</div>
