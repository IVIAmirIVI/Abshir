<span @class([
    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset',
    'bg-green-50 text-green-800 ring-green-600/15' => $order->operational_status === \App\Enums\OrderOperationalStatus::Delivered,
    'bg-stone-100 text-gray-600 ring-stone-300' => $order->operational_status === \App\Enums\OrderOperationalStatus::Cancelled,
    'bg-stone-50 text-brand-primary ring-stone-200' => $order->operational_status === \App\Enums\OrderOperationalStatus::New,
    'bg-orange-50 text-orange-900 ring-orange-500/20' => !in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled, \App\Enums\OrderOperationalStatus::New], true),
])><span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status) }}</span>
