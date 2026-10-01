<section class="space-y-4 rounded bg-white p-5 shadow">
    <div class="flex flex-wrap justify-between gap-3">
        <h3 class="font-semibold">{{ __('orders.ui_delivery_pricing') }}</h3>
        @if($currentDeliveryPricing)
            <span>{{ __('orders.ui_version') }} <x-ltr>{{ $currentDeliveryPricing->version }}</x-ltr> · {{ \App\Support\UiLabels::label('orders.delivery_statuses', $currentDeliveryPricing->status) }}</span>
        @endif
    </div>
    <p>{{ __('orders.ui_pricing_class_label') }} <strong>{{ ($order->pricing_class ? \App\Support\UiLabels::label('orders.pricing_classes', $order->pricing_class) : null) ?? __('common.operational_unavailable') }}</strong></p>

    @if(!$order->destination_area_id)
        <p class="rounded bg-amber-50 p-3">{{ __('orders.ui_historic_destination_help') }}</p>
        @can('delivery_pricing.apply')
            <form method="POST" action="{{ route('orders.delivery-pricing.destination', $order) }}" class="flex flex-wrap gap-2">
                @csrf
                @method('PATCH')
                <select name="destination_area_id" class="min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300">
                    @foreach($destinationAreas as $area)
                        <option value="{{ $area->id }}">{{ $area->path() }}</option>
                    @endforeach
                </select>
                <x-primary-button>{{ __('orders.ui_confirm_destination') }}</x-primary-button>
            </form>
        @endcan
    @else
        <p>{{ __('orders.ui_destination') }} {{ $order->destinationArea->path() }}</p>
    @endif

    @can('delivery_pricing.view')
        <form method="GET" action="{{ route('orders.show', $order) }}" class="flex flex-wrap gap-2">
            <select name="preview_from_area_id" class="min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300">
                @foreach($pricingOrigins as $area)
                    <option value="{{ $area->id }}" @selected(request('preview_from_area_id', $suggestedOriginId) == $area->id)>{{ $area->path() }}</option>
                @endforeach
            </select>
            <x-secondary-button type="submit">{{ __('orders.ui_preview_suggested') }}</x-secondary-button>
        </form>
    @endcan

    @if($pricingPreview)
        <div class="rounded border p-3">
            <p><strong>{{ __('orders.ui_preview') }}</strong> {{ $pricingPreview['origin']->path() }} → {{ $pricingPreview['destination']->path() }} · {{ $order->pricing_class ? \App\Support\UiLabels::label('orders.pricing_classes', $order->pricing_class) : null }}</p>
            @if($pricingPreview['rule'])
                <p><x-money :amount="$pricingPreview['rule']->delivery_unit_fee" /> <x-ltr>{{ $pricingPreview['currency']->code }}</x-ltr> {{ __('orders.ui_per_captain_rule') }} <x-ltr>{{ $order->ordered_at->format('Y-m-d H:i') }}</x-ltr>.</p>
                @can('delivery_pricing.apply')
                    <form method="POST" action="{{ route('orders.delivery-pricing.apply', $order) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="from_area_id" value="{{ $pricingPreview['origin']->id }}">
                        <x-primary-button>{{ __('orders.ui_create_pricing') }}</x-primary-button>
                    </form>
                @endcan
            @else
                <p class="font-semibold text-red-700">{{ __('orders.ui_no_rule') }}</p>
            @endif
        </div>
    @endif

    @if($currentDeliveryPricing)
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <p>{{ __('orders.ui_origin') }} {{ $currentDeliveryPricing->fromArea->path() }}</p>
            <p>{{ __('orders.ui_class_snapshot') }} {{ ($currentDeliveryPricing->order_pricing_class_snapshot ? \App\Support\UiLabels::label('orders.pricing_classes', $currentDeliveryPricing->order_pricing_class_snapshot) : null) ?? __('orders.ui_legacy_unclassified') }}</p>
            <p>{{ __('orders.ui_suggested_unit') }} <x-money :amount="$currentDeliveryPricing->suggested_unit_fee" /> <x-ltr>{{ $currentDeliveryPricing->currency->code }}</x-ltr></p>
            <p>{{ __('orders.ui_applied_unit') }} <x-money :amount="$currentDeliveryPricing->applied_unit_fee" /></p>
            <p>{{ __('orders.ui_captain_units') }} <x-ltr>{{ $currentDeliveryPricing->captain_count_snapshot }}</x-ltr></p>
            <p>{{ __('orders.ui_gross') }} <x-money :amount="$currentDeliveryPricing->gross_delivery_total" /></p>
            <p>{{ __('orders.ui_discount') }} <x-money :amount="$currentDeliveryPricing->discount_amount" /></p>
            <p>{{ __('orders.ui_customer_delivery') }} <x-money :amount="$currentDeliveryPricing->customer_delivery_total" /></p>
            @can('delivery_financial_details.view')
                <p>{{ __('orders.ui_captain_share') }} <x-ltr>{{ $currentDeliveryPricing->captain_share_percent_snapshot }}</x-ltr>%</p>
            @endcan
        </div>

        @can('delivery_pricing.override')
            <form method="POST" action="{{ route('orders.delivery-pricing.override', $order) }}" class="grid gap-2 sm:grid-cols-3">
                @csrf
                <input type="hidden" name="from_area_id" value="{{ $currentDeliveryPricing->from_area_id }}">
                <x-text-input name="requested_unit_fee" type="number" step="0.0001" min="0.0001" placeholder="{{ __('orders.ui_requested_fee') }}" dir="ltr"/>
                <x-text-input name="reason" placeholder="{{ __('orders.ui_override_reason') }}" dir="auto"/>
                <x-primary-button>{{ __('orders.ui_request_override') }}</x-primary-button>
            </form>
        @endcan

        @can('delivery_discounts.apply')
            <form method="POST" action="{{ route('orders.delivery-pricing.discount', $order) }}" class="grid gap-2 sm:grid-cols-3">
                @csrf
                <x-text-input name="discount_amount" type="number" step="0.0001" min="0" placeholder="{{ __('orders.ui_delivery_discount') }}" dir="ltr"/>
                <x-text-input name="reason" placeholder="{{ __('orders.ui_discount_reason') }}" dir="auto"/>
                <x-primary-button>{{ __('orders.ui_apply_discount') }}</x-primary-button>
            </form>
        @endcan

        @can('delivery_financial_details.view')
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach($currentDeliveryPricing->allocations as $allocation)
                    <article class="rounded border p-3">
                        <strong>{{ $allocation->captain->user->name }}</strong>
                        <p>{{ __('orders.ui_entitlement') }} <x-money :amount="$allocation->captain_entitlement" /> · {{ __('orders.ui_discount_allocation') }} <x-money :amount="$allocation->discount_allocated" /></p>
                        <p>{{ __('orders.ui_customer_allocation') }} <x-money :amount="$allocation->customer_delivery_amount_allocated" /> · {{ __('orders.ui_company_collectible') }} <x-money :amount="$allocation->company_collectible" /> · {{ __('orders.ui_captain_shortfall') }} <x-money :amount="$allocation->captain_shortfall" /></p>
                    </article>
                @endforeach
            </div>
        @endcan
    @else
        <p>{{ __('orders.ui_no_final_pricing') }}</p>
    @endif
</section>
