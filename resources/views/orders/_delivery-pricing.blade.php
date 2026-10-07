<section class="space-y-4 rounded-xl border border-brand-primary/10 bg-white p-4 shadow-sm sm:p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-semibold text-brand-primary">{{ __('orders.ui_delivery_pricing') }}</h3>
        @if($currentDeliveryPricing)<span class="text-sm text-gray-600">{{ __('orders.ui_version') }} <x-ltr>{{ $currentDeliveryPricing->version }}</x-ltr> · {{ \App\Support\UiLabels::label('orders.delivery_statuses', $currentDeliveryPricing->status) }}</span>@endif
    </div>
    @if(!$order->destination_area_id)
        <p class="rounded-lg bg-amber-50 p-3 text-amber-900">{{ __('orders.ui_historic_destination_help') }}</p>
        @if($orderMutable)@can('delivery_pricing.apply')
            <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.delivery-pricing.destination', $order) }}" class="flex flex-col gap-2 sm:flex-row">@csrf @method('PATCH')
                <select name="destination_area_id" class="w-full rounded-lg border-gray-300 sm:w-auto">@foreach($destinationAreas as $area)<option value="{{ $area->id }}">{{ $area->path() }}</option>@endforeach</select>
                <x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_confirm_destination') }}</x-primary-button>
            </form>
        @endcan @endif
    @endif
    @if($pricingProblem)<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $pricingProblem }}</p>@endif
    @if($pricingPreview && !$pricingPreview['is_frozen'])
        @if($pricingPreview['complete'])
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-green-200 bg-green-50 p-3">
                <p class="font-semibold text-green-950">{{ __('orders.ui_suggested_delivery_fee') }} <x-money :amount="$pricingPreview['rule']->delivery_unit_fee" /> <x-ltr>{{ $pricingPreview['currency']->code }}</x-ltr></p>
                @if(!$currentDeliveryPricing)@if($orderMutable)@can('delivery_pricing.apply')<form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.delivery-pricing.apply', $order) }}">@csrf<input type="hidden" name="from_area_id" value="{{ $pricingPreview['origin']->id }}"><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_create_pricing') }}</x-primary-button></form>@endcan @endif @endif
            </div>
            @if($pricingPreview['is_multi_shop'])<p class="text-sm text-gray-600">{{ __('orders.ui_multi_shop_highest_route_used') }}</p>@endif
        @else
            <p class="rounded-lg bg-red-50 p-3 font-semibold text-red-800">{{ __('orders.ui_multi_shop_route_missing_rule') }}</p>
        @endif
        <details class="rounded-lg border border-gray-200 p-3" @if(!$pricingPreview['complete']) open @endif>
            <summary class="cursor-pointer text-sm font-medium">{{ __('orders.ui_shop_route_proposals') }}</summary>
            <div class="mt-3 space-y-2 text-sm">
                @foreach($pricingPreview['routes'] as $route)
                    <p @class(['rounded-lg p-2', 'bg-green-50 font-semibold text-green-950' => $route['is_selected_max'], 'bg-stone-50' => !$route['is_selected_max']])>
                        <span>{{ $route['shop']->shop_name_snapshot }}:</span>
                        @if($route['origin'])
                            {{ __('orders.ui_route_from_to', ['from' => $route['origin']->path(), 'to' => $route['destination']->path()]) }}
                            — @if($route['rule'])<x-money :amount="$route['proposed_unit_fee']" /> <x-ltr>{{ $pricingPreview['currency']->code }}</x-ltr>@else{{ __('orders.ui_no_active_matching_rule_for_shop') }}@endif
                        @else
                            {{ __('orders.ui_missing_shop_origin_area') }}
                        @endif
                        @if($route['is_selected_max']) · {{ __('orders.ui_selected_highest_route') }}@endif
                    </p>
                @endforeach
            </div>
        </details>
    @endif
    @if($currentDeliveryPricing)
        <div class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-2">
            <p>{{ __('orders.ui_suggested_unit') }} <strong><x-money :amount="$currentDeliveryPricing->suggested_unit_fee" /> <x-ltr>{{ $currentDeliveryPricing->currency->code }}</x-ltr></strong></p>
            <p>{{ __('orders.ui_final_delivery_fee') }} <strong><x-money :amount="$currentDeliveryPricing->applied_unit_fee" /> <x-ltr>{{ $currentDeliveryPricing->currency->code }}</x-ltr></strong></p>
        </div>
        @if($orderMutable)@can('delivery_pricing.override')
            <details class="rounded-lg border border-gray-200 p-3"><summary class="cursor-pointer font-medium text-brand-primary">{{ __('orders.ui_adjust_delivery_fee') }}</summary>
                <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.delivery-pricing.override', $order) }}" class="mt-3 grid gap-2 sm:grid-cols-3">@csrf
                    <input type="hidden" name="from_area_id" value="{{ $currentDeliveryPricing->from_area_id }}"><x-text-input name="requested_unit_fee" type="number" step="0.0001" min="0.0001" placeholder="{{ __('orders.ui_requested_fee') }}" dir="ltr"/><x-input-error :messages="$errors->get('requested_unit_fee')" /><x-text-input name="reason" placeholder="{{ __('orders.ui_override_reason') }}" dir="auto"/><x-input-error :messages="$errors->get('reason')" /><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_request_override') }}</x-primary-button>
                </form>
            </details>
        @endcan @endif
        @if($orderMutable)@can('delivery_discounts.apply')
            <details class="rounded-lg border border-gray-200 p-3"><summary class="cursor-pointer text-sm font-medium">{{ __('orders.ui_apply_discount') }}</summary>
                <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.delivery-pricing.discount', $order) }}" class="mt-3 grid gap-2 sm:grid-cols-3">@csrf
                    <x-text-input name="discount_amount" type="number" step="0.0001" min="0" placeholder="{{ __('orders.ui_delivery_discount') }}" dir="ltr"/><x-input-error :messages="$errors->get('discount_amount')" /><x-text-input name="reason" placeholder="{{ __('orders.ui_discount_reason') }}" dir="auto"/><x-input-error :messages="$errors->get('reason')" /><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_apply_discount') }}</x-primary-button>
                </form>
            </details>
        @endcan @endif
        <details class="rounded-lg border border-gray-200 p-3"><summary class="cursor-pointer text-sm font-medium">{{ __('orders.ui_delivery_pricing_details') }}</summary>
            <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4"><p><strong>{{ __('orders.ui_from_explicit') }}</strong> {{ $currentDeliveryPricing->fromArea->path() }}</p><p><strong>{{ __('orders.ui_to_explicit') }}</strong> {{ $currentDeliveryPricing->toArea->path() }}</p><p>{{ __('orders.ui_class_snapshot') }} {{ ($currentDeliveryPricing->order_pricing_class_snapshot ? \App\Support\UiLabels::label('orders.pricing_classes', $currentDeliveryPricing->order_pricing_class_snapshot) : null) ?? __('orders.ui_legacy_unclassified') }}</p><p>{{ __('orders.ui_gross') }} <x-money :amount="$currentDeliveryPricing->gross_delivery_total" /></p><p>{{ __('orders.ui_discount') }} <x-money :amount="$currentDeliveryPricing->discount_amount" /></p><p>{{ __('orders.ui_customer_delivery') }} <x-money :amount="$currentDeliveryPricing->customer_delivery_total" /></p>@can('delivery_financial_details.view')<p>{{ __('orders.ui_captain_share') }} <x-ltr>{{ $currentDeliveryPricing->captain_share_percent_snapshot }}</x-ltr>%</p>@endcan</div>
            @if($currentDeliveryPricing->routes->isNotEmpty())<div class="mt-3 space-y-1 border-t pt-3 text-sm">@foreach($currentDeliveryPricing->routes as $route)<p @class(['font-semibold text-green-800' => $route->is_selected_max])>{{ $route->shop_name_snapshot }}: {{ __('orders.ui_route_from_to', ['from' => $route->fromArea->path(), 'to' => $route->toArea->path()]) }} — <x-money :amount="$route->proposed_unit_fee" /> <x-ltr>{{ $currentDeliveryPricing->currency->code }}</x-ltr>@if($route->is_selected_max) · {{ __('orders.ui_selected_highest_route') }}@endif</p>@endforeach</div>@endif
            @can('delivery_financial_details.view')<div class="mt-3 grid gap-3 lg:grid-cols-2">@foreach($currentDeliveryPricing->allocations as $allocation)<article class="rounded border p-3"><strong>{{ $allocation->captain->user->name }}</strong><p>{{ __('orders.ui_entitlement') }} <x-money :amount="$allocation->captain_entitlement" /> · {{ __('orders.ui_discount_allocation') }} <x-money :amount="$allocation->discount_allocated" /></p><p>{{ __('orders.ui_customer_allocation') }} <x-money :amount="$allocation->customer_delivery_amount_allocated" /> · {{ __('orders.ui_company_collectible') }} <x-money :amount="$allocation->company_collectible" /> · {{ __('orders.ui_captain_shortfall') }} <x-money :amount="$allocation->captain_shortfall" /></p></article>@endforeach</div>@endcan
        </details>
    @elseif(!$pricingPreview)
        <p class="text-sm text-gray-600">{{ __('orders.ui_no_final_pricing') }}</p>
    @endif
</section>
