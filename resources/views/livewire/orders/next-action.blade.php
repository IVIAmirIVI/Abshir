<section class="rounded-xl border border-orange-200 bg-orange-50 p-4 shadow-sm sm:p-5">
    <p class="text-xs font-bold uppercase tracking-wide text-orange-700">{{ __('orders.ui_next_action') }}</p>
    <h3 class="mt-1 text-lg font-bold text-gray-900">{{ __('orders.next_steps.'.$step) }}</h3>
    @if($step === 'status' && auth()->user()->can('orders.status.update') && ! $order->cancellationIntent && $order->operational_status->next())
        <form method="POST" action="{{ route('orders.status', $order) }}" x-data="{ submitting: false }" x-on:submit="submitting = true" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">@csrf @method('PATCH')<input type="hidden" name="operational_status" value="{{ $order->operational_status->next()->value }}"><label><span class="text-sm">{{ __('common.operational_reason_notes') }}</span><x-text-input name="reason" class="mt-1 w-full"/></label><x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_move_to') }} {{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status->next()) }}</x-primary-button></form>
    @endif
    @if(!in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
        <p class="mt-3 text-xs text-gray-600">{{ __('orders.ui_financial_posting_waits_for_final_prerequisites') }}</p>
    @elseif(auth()->user()->can('captain_financials.view'))
        <a href="{{ route('captain-financials.index') }}" class="mt-3 inline-block text-sm font-semibold text-brand-primary underline">{{ __('admin.navigation.captain_financials') }}</a>
    @endif
</section>
