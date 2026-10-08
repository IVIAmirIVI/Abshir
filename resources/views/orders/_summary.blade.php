<section class="overflow-hidden rounded-xl border border-brand-primary/10 bg-white shadow-sm">
    <div class="border-b border-gray-100 bg-gradient-to-l from-green-50 to-white p-4 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-sm text-gray-500">{{ __('orders.ui_order_summary') }}</p>
                <h3 class="mt-1 break-words text-xl font-bold text-brand-primary">{{ $order->customer_name_snapshot }}</h3>
                <p class="mt-1"><x-ltr>{{ $order->customer_phone_snapshot }}</x-ltr></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="rounded-full bg-brand-primary px-3 py-1 text-sm font-semibold text-white">{{ __('orders.ui_operational_status_label') }}: {{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status) }}</span>
                <span class="rounded-full bg-orange-100 px-3 py-1 text-sm font-semibold text-orange-900">{{ __('orders.ui_actual_price_status_label') }}: {{ \App\Support\UiLabels::label('orders.pricing_statuses', $order->pricing_status) }}</span>
            </div>
        </div>
    </div>
    <div class="grid gap-4 p-4 text-sm sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
        <div><p class="text-xs font-medium text-gray-500">{{ __('orders.ui_delivery_address') }}</p><p class="mt-1 break-words">{{ $order->recipient_name_snapshot }} · {{ $order->area_path_snapshot }} · {{ $order->address_line_1_snapshot }} {{ $order->landmark_snapshot }}</p></div>
        <div><p class="text-xs font-medium text-gray-500">{{ __('common.terms.branch') }}</p><p class="mt-1 font-medium">{{ $order->branch->name }}</p></div>
        <div><p class="text-xs font-medium text-gray-500">{{ __('orders.ui_ordered') }}</p><p class="mt-1"><x-ltr>{{ $order->ordered_at->format('Y-m-d H:i:s') }}</x-ltr></p></div>
        <div><p class="text-xs font-medium text-gray-500">{{ __('orders.ui_pricing_class_label') }}</p><p class="mt-1 font-medium">{{ ($order->pricing_class ? \App\Support\UiLabels::label('orders.pricing_classes', $order->pricing_class) : null) ?? __('orders.ui_class_unavailable') }}</p></div>
    </div>
    @if($order->notes)<p class="whitespace-pre-wrap break-words px-4 pb-3 text-sm">{{ $order->notes }}</p>@endif
    <div class="flex flex-col gap-2 border-t border-gray-100 bg-gray-50 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <span>{{ __('orders.ui_estimated_items') }} <strong><x-money :amount="$order->estimatedItemsTotal()" /></strong></span>
        <span>{{ __('orders.ui_actual_items') }} <strong><x-money :amount="$order->actualItemsTotal()" /></strong></span>
    </div>
</section>
