<section class="space-y-4 rounded bg-white p-5 shadow">
    <div class="flex flex-wrap justify-between gap-3">
        <h3 class="font-semibold">Delivery pricing</h3>
        @if($currentDeliveryPricing)
            <span>Version {{ $currentDeliveryPricing->version }} · {{ $currentDeliveryPricing->status->value }}</span>
        @endif
    </div>
    <p>Order Pricing Class: <strong>{{ $order->pricing_class?->label() ?? 'Unavailable' }}</strong></p>

    @if(!$order->destination_area_id)
        <p class="rounded bg-amber-50 p-3">Historical destination Area requires explicit administrative confirmation before delivery pricing.</p>
        @can('delivery_pricing.apply')
            <form method="POST" action="{{ route('orders.delivery-pricing.destination', $order) }}" class="flex flex-wrap gap-2">
                @csrf
                @method('PATCH')
                <select name="destination_area_id" class="rounded border-gray-300">
                    @foreach($destinationAreas as $area)
                        <option value="{{ $area->id }}">{{ $area->path() }}</option>
                    @endforeach
                </select>
                <x-primary-button>Confirm historical destination</x-primary-button>
            </form>
        @endcan
    @else
        <p>Destination: {{ $order->destinationArea->path() }}</p>
    @endif

    @can('delivery_pricing.view')
        <form method="GET" action="{{ route('orders.show', $order) }}" class="flex flex-wrap gap-2">
            <select name="preview_from_area_id" class="rounded border-gray-300">
                @foreach($pricingOrigins as $area)
                    <option value="{{ $area->id }}" @selected(request('preview_from_area_id', $suggestedOriginId) == $area->id)>{{ $area->path() }}</option>
                @endforeach
            </select>
            <x-secondary-button type="submit">Preview suggested price</x-secondary-button>
        </form>
    @endcan

    @if($pricingPreview)
        <div class="rounded border p-3">
            <p><strong>Preview:</strong> {{ $pricingPreview['origin']->path() }} → {{ $pricingPreview['destination']->path() }} · {{ $order->pricing_class?->label() }}</p>
            @if($pricingPreview['rule'])
                <p>{{ $pricingPreview['rule']->delivery_unit_fee }} {{ $pricingPreview['currency']->code }} per active Captain, based on the rule applicable at {{ $order->ordered_at->format('Y-m-d H:i') }}.</p>
                @can('delivery_pricing.apply')
                    <form method="POST" action="{{ route('orders.delivery-pricing.apply', $order) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="from_area_id" value="{{ $pricingPreview['origin']->id }}">
                        <x-primary-button>Create pricing snapshot</x-primary-button>
                    </form>
                @endcan
            @else
                <p class="font-semibold text-red-700">NO DELIVERY PRICE RULE</p>
            @endif
        </div>
    @endif

    @if($currentDeliveryPricing)
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <p>Origin: {{ $currentDeliveryPricing->fromArea->path() }}</p>
            <p>Pricing class snapshot: {{ $currentDeliveryPricing->order_pricing_class_snapshot?->label() ?? 'Legacy unclassified' }}</p>
            <p>Suggested unit: {{ $currentDeliveryPricing->suggested_unit_fee }} {{ $currentDeliveryPricing->currency->code }}</p>
            <p>Applied unit: {{ $currentDeliveryPricing->applied_unit_fee }}</p>
            <p>Captains/units: {{ $currentDeliveryPricing->captain_count_snapshot }}</p>
            <p>Gross: {{ $currentDeliveryPricing->gross_delivery_total }}</p>
            <p>Discount: {{ $currentDeliveryPricing->discount_amount }}</p>
            <p>Customer delivery total: {{ $currentDeliveryPricing->customer_delivery_total }}</p>
            @can('delivery_financial_details.view')
                <p>Captain share snapshot: {{ $currentDeliveryPricing->captain_share_percent_snapshot }}%</p>
            @endcan
        </div>

        @can('delivery_pricing.override')
            <form method="POST" action="{{ route('orders.delivery-pricing.override', $order) }}" class="grid gap-2 sm:grid-cols-3">
                @csrf
                <input type="hidden" name="from_area_id" value="{{ $currentDeliveryPricing->from_area_id }}">
                <x-text-input name="requested_unit_fee" type="number" step="0.0001" min="0.0001" placeholder="Requested unit fee"/>
                <x-text-input name="reason" placeholder="Required override reason"/>
                <x-primary-button>Request/apply override</x-primary-button>
            </form>
        @endcan

        @can('delivery_discounts.apply')
            <form method="POST" action="{{ route('orders.delivery-pricing.discount', $order) }}" class="grid gap-2 sm:grid-cols-3">
                @csrf
                <x-text-input name="discount_amount" type="number" step="0.0001" min="0" placeholder="Delivery discount"/>
                <x-text-input name="reason" placeholder="Reason for non-zero discount"/>
                <x-primary-button>Apply discount</x-primary-button>
            </form>
        @endcan

        @can('delivery_financial_details.view')
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach($currentDeliveryPricing->allocations as $allocation)
                    <article class="rounded border p-3">
                        <strong>{{ $allocation->captain->user->name }}</strong>
                        <p>Entitlement: {{ $allocation->captain_entitlement }} · Discount allocation: {{ $allocation->discount_allocated }}</p>
                        <p>Customer allocation: {{ $allocation->customer_delivery_amount_allocated }} · Company collectible: {{ $allocation->company_collectible }} · Captain shortfall: {{ $allocation->captain_shortfall }}</p>
                    </article>
                @endforeach
            </div>
        @endcan
    @else
        <p>No finalized delivery pricing. A rule may be previewed, but final pricing requires at least one active Captain and configured Captain share.</p>
    @endif
</section>
