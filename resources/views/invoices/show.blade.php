<x-app-layout>
    <x-slot name="header"><h2 class="break-all text-xl font-semibold">{{ $invoice->invoice_number }}</h2></x-slot>

    <div class="mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-6">
        @if(session('status'))<p class="rounded bg-green-50 p-4">{{ session('status') }}</p>@endif
        @if($errors->any())
            <div class="rounded bg-red-50 p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <section class="space-y-3 rounded bg-white p-5 shadow">
            <div class="flex flex-wrap justify-between gap-3">
                <div><h3 class="font-semibold">{{ $invoice->status->value }} · Version {{ $invoice->version }}</h3><p>Order {{ $invoice->order_number_snapshot }}</p></div>
                <a class="underline" target="_blank" href="{{ route('orders.invoices.print', [$invoice->order, $invoice]) }}">Print view</a>
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <p>{{ $invoice->customer_name_snapshot }} · {{ $invoice->customer_phone_snapshot }}</p>
                <p>{{ $invoice->area_snapshot }} · {{ $invoice->address_snapshot }}</p>
                <p>Ordered: {{ $invoice->ordered_at_snapshot->format('Y-m-d H:i') }}</p>
                <p>Issued: {{ $invoice->issued_at?->format('Y-m-d H:i') ?? 'Not issued' }}</p>
            </div>
        </section>

        <section class="space-y-3 rounded bg-white p-5 shadow">
            <h3 class="font-semibold">Customer charges</h3>
            @forelse($invoice->items as $item)
                <article class="grid gap-1 rounded border p-3 sm:grid-cols-4">
                    <strong>{{ $item->description }}</strong><span>{{ $item->quantity }} {{ $item->unit_name_snapshot }}</span><span>{{ $item->unit_price }} {{ $invoice->currency?->code }}</span><span class="font-semibold">{{ $item->line_total }}</span>
                </article>
            @empty
                <p>Refresh the draft after pricing is complete.</p>
            @endforelse
            <div class="ml-auto max-w-sm space-y-1 border-t pt-3 text-right">
                <p>Items: {{ $invoice->items_subtotal ?? '—' }}</p><p>Delivery: {{ $invoice->delivery_total ?? '—' }}</p><p class="text-xl font-bold">Total: {{ $invoice->grand_total ?? '—' }} {{ $invoice->currency?->code }}</p>
            </div>
        </section>

        <section class="flex flex-wrap gap-3 rounded bg-white p-5 shadow">
            @if(in_array($invoice->status, [\App\Enums\InvoiceStatus::Draft, \App\Enums\InvoiceStatus::Ready], true))
                @can('invoices.create')<form method="POST" action="{{ route('orders.invoices.refresh', [$invoice->order, $invoice]) }}">@csrf<x-primary-button>Refresh and validate READY</x-primary-button></form>@endcan
            @endif
            @if($invoice->status === \App\Enums\InvoiceStatus::Ready)
                @can('invoices.issue')<form method="POST" action="{{ route('orders.invoices.issue', [$invoice->order, $invoice]) }}">@csrf<x-primary-button>Issue Invoice</x-primary-button></form>@endcan
            @endif

            @if($invoice->status === \App\Enums\InvoiceStatus::Issued)
                @can('invoice_collections.create')
                    @if(!$invoice->collection && !$settledCorrection)
                        <form method="POST" action="{{ route('invoices.collection.store', $invoice) }}" class="grid w-full gap-3 sm:grid-cols-2" x-data="{ method: '{{ old('collection_method', 'CASH') }}' }">
                            @csrf
                            <p class="sm:col-span-2">Full collection: <strong>{{ $invoice->grand_total }} {{ $invoice->currency->code }}</strong></p>
                            <label class="space-y-1"><span>Collection method</span><select name="collection_method" x-model="method" class="w-full rounded border-gray-300" required><option value="CASH">CASH</option><option value="SHAM_CASH">SHAM_CASH</option></select></label>
                            <label class="space-y-1"><span>Collected at</span><x-text-input name="collected_at" type="datetime-local" step="1" :value="old('collected_at', now()->format('Y-m-d\TH:i:s'))" required/></label>
                            <div x-show="method === 'CASH'" class="rounded bg-blue-50 p-3 sm:col-span-2">
                                @if($primaryCollector)<p>Collector: <strong>{{ $primaryCollector->captain->user->name }}</strong> (active PRIMARY_COLLECTION Captain)</p>@else<p class="text-red-700">No active PRIMARY_COLLECTION Captain is available for CASH.</p>@endif
                            </div>
                            <div x-show="method === 'SHAM_CASH'" class="space-y-2 rounded bg-emerald-50 p-3 sm:col-span-2">
                                <p>Payment was transferred directly to the company owner's Sham Cash account. No Captain is recorded as collector.</p>
                                <x-text-input name="payment_reference" :value="old('payment_reference')" placeholder="Optional Sham Cash reference" class="w-full"/>
                            </div>
                            <x-text-input name="notes" :value="old('notes')" placeholder="Optional notes" class="sm:col-span-2"/>
                            <x-primary-button>Record full collection</x-primary-button>
                        </form>
                    @endif
                @endcan
                @if(!$invoice->collection && !$settledCorrection)
                    @can('invoices.void')<form method="POST" action="{{ route('orders.invoices.void', [$invoice->order, $invoice]) }}" class="flex flex-wrap gap-2">@csrf<x-text-input name="reason" placeholder="Required void reason"/><button class="rounded bg-red-700 px-3 py-2 text-white">Void</button></form>@endcan
                    @can('invoices.replace')<form method="POST" action="{{ route('orders.invoices.replace', [$invoice->order, $invoice]) }}" class="flex flex-wrap gap-2">@csrf<x-text-input name="reason" placeholder="Required replacement reason"/><button class="rounded bg-amber-700 px-3 py-2 text-white">Create replacement</button></form>@endcan
                @endif
            @endif
        </section>

        @if($invoice->collection)
            <section class="rounded bg-green-50 p-5">
                <h3 class="font-semibold">Collected in full · {{ $invoice->collection->collection_method?->value ?? 'Legacy method unknown' }}</h3>
                <p>{{ $invoice->collection->amount }} {{ $invoice->currency->code }} · {{ $invoice->collection->collected_at->format('Y-m-d H:i') }}</p>
                @if($invoice->collection->collection_method === \App\Enums\InvoiceCollectionMethod::Cash)
                    <p>Collector: {{ $invoice->collection->captain?->user?->name }}</p>
                @elseif($invoice->collection->collection_method === \App\Enums\InvoiceCollectionMethod::ShamCash)
                    <p>Direct to company Sham Cash</p>
                    @if($invoice->collection->payment_reference)<p>Reference: {{ $invoice->collection->payment_reference }}</p>@endif
                @endif
            </section>
        @endif
        @if($settledCorrection)
            <section class="rounded bg-blue-50 p-5">Payment satisfied by original Collection {{ $settledCorrection->original_collection_id }} and its completed financial correction. No second customer payment is due.</section>
        @endif

        @if($invoice->collection && auth()->user()->can('invoice_corrections.view'))
            <section class="space-y-4 rounded bg-white p-5 shadow">
                <h3 class="font-semibold">Collected Invoice financial correction</h3>
                <p>Original Collection: {{ $invoice->collection->amount }} {{ $invoice->currency->code }} via {{ $invoice->collection->collection_method->value }}. The original receipt and Invoice remain historical facts.</p>
                @foreach($corrections as $correction)
                    <article class="space-y-2 rounded border p-4">
                        <p><strong>{{ $correction->kind->value }}</strong> · {{ $correction->state->value }} · {{ $correction->reason }}</p>
                        <p>Original {{ $correction->original_amount }} · Corrected {{ $correction->corrected_amount ?? 'Pending approval' }} · Customer delta {{ $correction->customer_delta ?? 'Pending approval' }} {{ $invoice->currency->code }}</p>
                        @if($correction->kind === \App\Enums\InvoiceFinancialCorrectionKind::CancelAndRefund)
                            <p>Shop returns and cancellation intent must be completed before finalization. <a class="underline" href="{{ route('orders.show', $invoice->order) }}">View Order progress</a></p>
                        @endif
                        @if($correction->refund)
                            <p>Customer refund completed: {{ $correction->refund->amount }} via {{ $correction->refund->method->value }}.
                                @if($correction->refund->refunding_captain_id) Historical refunding Captain #{{ $correction->refund->refunding_captain_id }}. @else Company Sham Cash refund. @endif
                                @can('customer_refunds.view_evidence')<a class="underline" href="{{ route('customer-refunds.evidence', $correction->refund) }}">Private evidence</a>@endcan
                            </p>
                        @elseif($correction->state === \App\Enums\InvoiceFinancialCorrectionState::Prepared)
                            <p>Required customer refund: {{ \Brick\Math\BigDecimal::of($correction->customer_delta)->negated() }} via original {{ $invoice->collection->collection_method->value }}.
                                @if($invoice->collection->captain_id) Historical refunding Captain #{{ $invoice->collection->captain_id }}. @endif
                            </p>
                            @can('customer_refunds.record')
                                <form method="POST" enctype="multipart/form-data" action="{{ route('invoice-corrections.refund', $correction) }}" class="grid gap-2 sm:grid-cols-2">
                                    @csrf
                                    <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <label>Refunded at <x-text-input name="refunded_at" type="datetime-local" step="1" :value="now()->format('Y-m-d\TH:i:s')" required/></label>
                                    <label>Reference <x-text-input name="reference" required/></label>
                                    <label class="sm:col-span-2">JPEG, PNG or WebP proof, at most 5 MB <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required></label>
                                    <x-primary-button>Record actual refund</x-primary-button>
                                </form>
                            @endcan
                        @endif
                        @if($correction->state === \App\Enums\InvoiceFinancialCorrectionState::Requested)
                            @can('invoice_corrections.prepare')<form method="POST" action="{{ route('invoice-corrections.prepare', $correction) }}">@csrf<x-primary-button>Prepare financial correction</x-primary-button></form>@endcan
                        @endif
                        @if(in_array($correction->state, [\App\Enums\InvoiceFinancialCorrectionState::Requested, \App\Enums\InvoiceFinancialCorrectionState::Prepared], true) && !$correction->refund)
                            @can('invoice_corrections.prepare')<form method="POST" action="{{ route('invoice-corrections.abandon', $correction) }}">@csrf<button class="rounded bg-gray-700 px-3 py-2 text-white">Abandon unfunded request</button></form>@endcan
                        @endif
                        @if($correction->state === \App\Enums\InvoiceFinancialCorrectionState::ReadyToFinalize)
                            @can('invoice_corrections.finalize')<form method="POST" action="{{ route('invoice-corrections.finalize', $correction) }}">@csrf<x-primary-button>Finalize correction</x-primary-button></form>@endcan
                        @endif
                    </article>
                @endforeach
                @if($correctionSource && !$corrections->contains(fn ($correction) => in_array($correction->state->value, ['REQUESTED','PREPARED','READY_TO_FINALIZE'], true)))
                    @can('invoice_corrections.request')
                        <form method="POST" action="{{ route('invoices.corrections.request', $invoice) }}" class="space-y-3 rounded border p-4">
                            @csrf
                            <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <p>Request a corrected price or cancellation. Amounts below are editable for REPRICE; cancellation ignores them.</p>
                            <label>Kind <select name="kind" class="rounded border-gray-300"><option value="REPRICE">REPRICE</option><option value="CANCEL_AND_REFUND">CANCEL_AND_REFUND</option></select></label>
                            @foreach($invoice->items as $index => $item)
                                <label class="block">{{ $item->description }} · corrected unit price
                                    <input type="hidden" name="items[{{ $index }}][invoice_item_id]" value="{{ $item->id }}">
                                    <x-text-input name="items[{{ $index }}][unit_price]" :value="$item->unit_price" required/>
                                </label>
                            @endforeach
                            @foreach($correctionSource['captain_allocations'] as $index => $allocation)
                                <label class="block">Captain #{{ $allocation['captain_id'] }} · corrected customer delivery allocation
                                    <input type="hidden" name="delivery[{{ $index }}][order_captain_id]" value="{{ $allocation['order_captain_id'] }}">
                                    <x-text-input name="delivery[{{ $index }}][customer_amount]" :value="$allocation['customer_delivery_amount_allocated']" required/>
                                </label>
                            @endforeach
                            <label class="block">Reason <x-text-input name="reason" required class="w-full"/></label>
                            <label class="block">Supporting explanation <textarea name="explanation" required class="w-full rounded border-gray-300"></textarea></label>
                            <x-primary-button>Request financial correction</x-primary-button>
                        </form>
                    @endcan
                @endif
            </section>
        @endif
        @if($invoice->void_reason)<p class="rounded bg-red-50 p-4">Void reason: {{ $invoice->void_reason }} · {{ $invoice->voider?->name }}</p>@endif
        @if($invoice->replacement_reason)<p class="rounded bg-amber-50 p-4">Replacement: {{ $invoice->replacement_reason }} · {{ $invoice->replacer?->name }} @if($invoice->replacementInvoice)<a class="underline" href="{{ route('orders.invoices.show', [$invoice->order, $invoice->replacementInvoice]) }}">Open replacement</a>@endif</p>@endif
    </div>
</x-app-layout>
