<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('shared_orders.financial_review') }} · <x-ltr>{{ $order->order_number }}</x-ltr></h2></x-slot>
    <div class="mx-auto max-w-5xl space-y-5 px-4 py-6" data-shared-financial-review>
        <a href="{{ route('orders.workflow', $order) }}" class="text-sm underline">{{ __('orders.workflow.return') }}</a>
        <p role="status" class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ __('shared_orders.preview_only') }}</p>
        <form method="GET" class="flex flex-wrap items-end gap-3"><label class="text-sm">{{ __('shared_orders.payment_method') }}<select name="method" class="mt-1 block rounded-lg border-gray-300">@foreach(['CASH', 'SHAM_CASH'] as $value)<option value="{{ $value }}" @selected($method === $value)>{{ __('financial.collection_methods.'.$value) }}</option>@endforeach</select></label><x-primary-button>{{ __('common.actions.view') }}</x-primary-button></form>
        @if($problem)<p role="alert" class="rounded-xl border p-4">{{ $problem }}</p>@endif
        @if($plan)
            <dl class="grid gap-4 rounded-xl border bg-white p-4 sm:grid-cols-2">
                @foreach(['items_subtotal' => 'principal', 'delivery_gross' => 'delivery_gross', 'discount_once' => 'discount', 'customer_invoice_total' => 'invoice_total', 'anticipated_cash_custody' => 'anticipated_custody', 'company_obligations' => 'obligations', 'company_delivery_net' => 'company_net', 'deposit_effect' => 'deposit_effect'] as $key => $label)
                    <div><dt class="text-sm text-gray-600">{{ __('shared_orders.'.$label) }}</dt><dd class="font-semibold"><x-money :amount="$plan[$key]" /> <x-ltr>{{ $plan['currency_code'] }}</x-ltr></dd></div>
                @endforeach
            </dl>
            <div class="grid gap-4 sm:grid-cols-2">@foreach($plan['participants'] as $row)<article class="space-y-2 rounded-xl border bg-white p-4"><h3 class="font-semibold">{{ $row['name'] }}</h3>@if($row['is_collector'])<p class="text-sm">{{ __('shared_orders.collector') }}</p>@endif<dl class="space-y-2">@foreach(['purchase_reimbursement' => 'reimbursement', 'delivery_entitlement' => 'entitlement', 'company_obligation' => 'obligation'] as $key => $label)<div><dt class="text-sm text-gray-600">{{ __('shared_orders.'.$label) }}</dt><dd><x-money :amount="$row[$key]" /> <x-ltr>{{ $plan['currency_code'] }}</x-ltr></dd></div>@endforeach</dl></article>@endforeach</div>
            <p class="text-sm text-gray-600">{{ __('shared_orders.pending_finance') }}</p>
        @endif
    </div>
</x-app-layout>
