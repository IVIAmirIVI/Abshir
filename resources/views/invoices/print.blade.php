<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\UiLabels::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body{font-family:Arial,sans-serif;max-width:900px;margin:2rem auto;padding:1rem;color:#111;overflow-wrap:anywhere}
        .top,.line,.totals div{display:flex;justify-content:space-between;gap:1rem}
        .top,.line{flex-wrap:wrap}.line{border-bottom:1px solid #ddd;padding:.75rem 0}
        .totals{margin-block-start:1.5rem;margin-inline-start:auto;max-width:360px}
        .totals div{align-items:baseline;flex-wrap:wrap}.totals div>*,.top>*,.line>*{min-width:0;max-width:100%}*{box-sizing:border-box}.grand{font-size:1.3rem;font-weight:bold}.print{margin:1rem 0;min-height:44px;max-width:100%;padding:.625rem 1rem;border:1px solid #164d3b;border-radius:.625rem;background:#164d3b;color:#fff;font:600 .875rem/1.5 Tahoma,Arial,sans-serif;cursor:pointer}.print:focus-visible{outline:2px solid #164d3b;outline-offset:3px}
        bdi[dir=ltr]{direction:ltr;unicode-bidi:isolate}
        @media print{.print{display:none}body{margin:0;max-width:none}.line,.totals{break-inside:avoid}}
        @media(max-width:500px){.top,.line{display:block}.line span{display:block;margin-top:.25rem}}
    </style>
</head>
<body>
    <button class="print" onclick="window.print()">{{ __('financial.ui.print_save_pdf') }}</button>
    <div class="top">
        <div><h1>{{ __('common.brand.name') }}</h1>
@include('invoices._shared-captains')
<p>{{ __('financial.ui.customer_invoice') }}</p><p>{{ \App\Support\UiLabels::label('financial.invoice_statuses', $invoice->status) }} · {{ $invoice->superseded_at ? __('financial.ui.historical_invoice') : __('financial.ui.current_invoice') }}</p></div>
        <div><strong><x-ltr>{{ $invoice->invoice_number }}</x-ltr></strong><p>{{ __('common.terms.order') }} <x-ltr>{{ $invoice->order_number_snapshot }}</x-ltr></p><p><x-ltr>{{ \App\Support\UiDate::datetime($invoice->issued_at ?? $invoice->created_at) }}</x-ltr></p></div>
    </div>
    <hr>
    <p dir="auto"><strong>{{ $invoice->customer_name_snapshot }}</strong><br><x-ltr>{{ $invoice->customer_phone_snapshot }}</x-ltr><br>{{ $invoice->area_snapshot }}<br>{{ $invoice->address_snapshot }}</p>
    <h2>{{ __('financial.ui.items_subtotal_heading') }}</h2>
    @foreach($invoice->items as $item)
        <div class="line"><span dir="auto">{{ $item->description }} · <x-ltr>{{ $item->quantity }}</x-ltr> {{ $item->unit_name_snapshot }}</span><span><x-money :amount="$item->unit_price" /> × <x-ltr>{{ $item->quantity }}</x-ltr></span><strong><x-money :amount="$item->line_total" :currency="$invoice->currency->code" /></strong></div>
    @endforeach
    <div class="totals">
        <div><span>{{ __('financial.ui.items_subtotal') }}</span><span><x-money :amount="$invoice->items_subtotal" /></span></div>
        <div><span>{{ __('financial.ui.delivery') }}</span><span><x-money :amount="$invoice->delivery_total" /></span></div>
        <div class="grand"><span>{{ __('financial.ui.invoice_total') }}</span><span><x-money :amount="$invoice->grand_total" :currency="$invoice->currency->code" /></span></div>
    </div>
    <p>{{ __('financial.ui.print_help') }}</p>
</body>
</html>
