@can('invoices.view')
    @if(!$order->invoices->contains(fn ($invoice) => $invoice->issued_at !== null) && !app(\App\Services\InvoiceService::class)->mutationProblem($order))
        <a href="{{ route('orders.invoices.preview', $order) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 max-w-full items-center justify-center rounded-lg border border-green-800 bg-green-800 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-green-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-800">{{ __('financial.ui.pre_delivery_preview') }}</a>
    @endif
@endcan
