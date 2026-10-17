<section class="rounded-xl border border-orange-200 bg-orange-50 p-4 shadow-sm sm:p-5">
    <p class="text-xs font-bold uppercase tracking-wide text-orange-700">{{ __('orders.ui_next_action') }}</p>
    <h3 class="mt-1 text-lg font-bold text-gray-900">{{ __('orders.next_steps.'.$step) }}</h3>
    @if($stepProblem)<p class="mt-2 text-sm text-amber-900">{{ $stepProblem }}</p>@endif
    @if(($collectionAvailability['available'] ?? false) && auth()->user()->can('invoices.view'))<a class="mt-3 inline-block rounded bg-brand-primary px-4 py-2 font-semibold text-white" href="{{ route('orders.invoices.show', [$order, $order->currentInvoice]) }}#invoice-collection">{{ __('financial.ui.record_collection') }}</a>@elseif($collectionAvailability['settled_correction'] ?? false)<p class="mt-2 text-sm">{{ __('orders.ui_payment_settled_correction') }}</p>@endif
    @if($stepProblem === __('domain.an_unresolved_invoice_financial_correction_blocks_original_order_posting') && $order->currentInvoice && auth()->user()->can('invoices.view') && auth()->user()->can('invoice_corrections.view'))
        <a class="mt-2 inline-block text-sm underline" href="{{ route('orders.invoices.show', [$order, $order->currentInvoice]) }}">{{ __('financial.ui.collected_correction') }}</a>
    @endif
    @if($canTransition)
        @if($step !== 'status')<details class="mt-3 rounded border border-orange-200 p-3"><summary class="cursor-pointer text-sm font-semibold">{{ __('orders.ui_move_to') }} {{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status->next()) }}</summary>@endif
        <form method="POST" action="{{ route('orders.status', $order) }}" x-data="{ submitting: false }" x-on:submit="submitting = true" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">@csrf @method('PATCH')<input type="hidden" name="operational_status" value="{{ $order->operational_status->next()->value }}"><label><span class="text-sm">{{ __('common.operational_reason_notes') }}</span><x-text-input name="reason" class="mt-1 w-full"/></label><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_move_to') }} {{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status->next()) }}</x-primary-button></form>
        @if($step !== 'status')</details>@endif
    @endif
    @if(! $order->cancellationIntent && !in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
        <p class="mt-3 text-xs text-gray-600">{{ __('orders.ui_financial_posting_waits_for_final_prerequisites') }}</p>
    @elseif(in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true) && auth()->user()->can('captain_financials.view'))
        <a href="{{ route('captain-financials.index') }}" class="mt-3 inline-block text-sm font-semibold text-brand-primary underline">{{ __('admin.navigation.captain_financials') }}</a>
    @endif
</section>
