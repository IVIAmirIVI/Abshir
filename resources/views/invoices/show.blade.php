<x-app-layout>
    @php($captainNames = $invoice->order->captainAssignments->mapWithKeys(fn ($assignment) => [$assignment->captain_id => $assignment->captain?->user?->name]))
    <x-slot name="header"><h2 class="break-all text-xl font-semibold"><x-ltr>{{ $invoice->invoice_number }}</x-ltr></h2></x-slot>

    <div class="financial-ui mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-6">
        @if(session('status'))<p class="rounded bg-green-50 p-4">{{ session('status') }}</p>@endif
        @if($errors->any())
            <div class="rounded bg-red-50 p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <a href="{{ route('orders.workflow', [$invoice->order, 'step' => 'invoice']) }}" class="app-button bg-brand-primary text-white" data-invoice-workflow-return>{{ __('orders.workflow.return') }}</a>

        <section class="space-y-3 rounded bg-white p-5 shadow">
            <div class="flex flex-wrap justify-between gap-3">
                <div><h3 class="font-semibold">{{ \App\Support\UiLabels::label('financial.invoice_statuses', $invoice->status) }} · {{ __('financial.ui.version') }} <x-ltr>{{ $invoice->version }}</x-ltr></h3><p>{{ __('common.terms.order') }} <x-ltr>{{ $invoice->order_number_snapshot }}</x-ltr></p></div>
                <a class="underline" target="_blank" rel="noopener" href="{{ route('orders.invoices.print', [$invoice->order, $invoice]) }}">{{ __('financial.ui.print_view') }}</a>
            </div>
            @include('invoices._shared-captains')
            @if($invoice->collection && app(\App\Services\SharedPurchaseBoundary::class)->hasSelectedSharedContract($invoice->order))<p class="rounded bg-amber-50 p-3 text-sm">{{ __('shared_orders.shared_correction_blocked') }}</p>@elseif($problem = app(\App\Services\InvoiceService::class)->retirementProblem($invoice->order))<p class="rounded bg-amber-50 p-3 text-sm">{{ $problem }}</p>@endif
            <p class="text-sm">{{ $invoice->superseded_at ? __('financial.ui.historical_invoice') : __('financial.ui.current_invoice') }}</p>
            <div class="grid gap-2 sm:grid-cols-2">
                <p>{{ $invoice->customer_name_snapshot }} · <x-ltr>{{ $invoice->customer_phone_snapshot }}</x-ltr></p>
                <p>{{ $invoice->area_snapshot }} · {{ $invoice->address_snapshot }}</p>
                <p>{{ __('financial.ui.ordered') }} <x-ltr>{{ \App\Support\UiDate::datetime($invoice->ordered_at_snapshot) }}</x-ltr></p>
                <p>{{ __('financial.ui.issued_label') }} @if($invoice->issued_at)<x-ltr>{{ \App\Support\UiDate::datetime($invoice->issued_at) }}</x-ltr>@else{{ __('financial.ui.not_issued') }}@endif</p>
            </div>
        </section>

        <section class="space-y-3 rounded bg-white p-5 shadow">
            <h3 class="font-semibold">{{ __('financial.ui.customer_charges') }}</h3>
            @forelse($invoice->items as $item)
                <article class="grid gap-1 rounded border p-3 sm:grid-cols-4">
                    <strong>{{ $item->description }}</strong><span><x-ltr>{{ $item->quantity }}</x-ltr> {{ $item->unit_name_snapshot }}</span><span><x-money :amount="$item->unit_price" /> <x-ltr>{{ $invoice->currency?->code }}</x-ltr></span><span class="font-semibold"><x-money :amount="$item->line_total" /></span>
                </article>
            @empty
                <p>{{ __('financial.ui.refresh_help') }}</p>
            @endforelse
            <div class="ms-auto max-w-sm space-y-1 border-t pt-3 text-end">
                <p>{{ __('financial.ui.items') }} @if($invoice->items_subtotal !== null)<x-money :amount="$invoice->items_subtotal" />@else{{ '—' }}@endif</p><p>{{ __('financial.ui.delivery') }} @if($invoice->delivery_total !== null)<x-money :amount="$invoice->delivery_total" />@else{{ '—' }}@endif</p><p class="text-xl font-bold">{{ __('financial.ui.total') }} @if($invoice->grand_total !== null)<x-money :amount="$invoice->grand_total" />@else{{ '—' }}@endif <x-ltr>{{ $invoice->currency?->code }}</x-ltr></p>
            </div>
        </section>

        <section class="flex flex-wrap gap-3 rounded bg-white p-5 shadow">
            @if(!$invoice->superseded_at && ! app(\App\Services\InvoiceService::class)->mutationProblem($invoice->order) && in_array($invoice->status, [\App\Enums\InvoiceStatus::Draft, \App\Enums\InvoiceStatus::Ready], true))
                @can('invoices.create')<form method="POST" action="{{ route('orders.invoices.refresh', [$invoice->order, $invoice]) }}">@csrf<x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.refresh_ready') }}</x-primary-button></form>@endcan
            @endif
            @if(!$invoice->superseded_at && ! app(\App\Services\InvoiceService::class)->mutationProblem($invoice->order) && $invoice->status === \App\Enums\InvoiceStatus::Ready)
                @can('invoices.issue')<form method="POST" action="{{ route('orders.invoices.issue', [$invoice->order, $invoice]) }}">@csrf<x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.issue_invoice') }}</x-primary-button></form>@endcan
            @endif

            @if($invoice->status === \App\Enums\InvoiceStatus::Issued)
                @can('invoice_collections.create')
                    @if($collectionAvailability['available'])
                        <form id="invoice-collection" method="POST" action="{{ route('invoices.collection.store', $invoice) }}" class="grid w-full gap-3 sm:grid-cols-2" x-data="{ method: '{{ old('collection_method', 'CASH') }}' }">
                            @csrf
                            <p class="sm:col-span-2">{{ __('financial.ui.full_collection') }} <strong><x-money :amount="$invoice->grand_total" /> <x-ltr>{{ $invoice->currency->code }}</x-ltr></strong></p>
                            <label class="space-y-1"><span>{{ __('financial.ui.collection_method') }}</span><select name="collection_method" x-model="method" class="min-w-0 max-w-full w-full rounded border-gray-300" required><option value="CASH">{{ __('financial.collection_methods.CASH') }}</option><option value="SHAM_CASH">{{ __('financial.collection_methods.SHAM_CASH') }}</option></select></label>
                            <label class="space-y-1"><span>{{ __('financial.ui.collected_at') }}</span><x-text-input name="collected_at" type="datetime-local" step="1" :value="old('collected_at', now()->format('Y-m-d\TH:i:s'))" required dir="ltr"/></label>
                            <div x-show="method === 'CASH'" class="rounded bg-blue-50 p-3 sm:col-span-2">
                                @if($primaryCollector)<p>{{ __('financial.ui.collector') }} <strong>{{ $primaryCollector->captain->user->name }}</strong> {{ __('financial.ui.active_collector') }}</p>@else<p class="text-red-700">{{ __('financial.ui.no_collector') }}</p>@endif
                            </div>
                            <div x-show="method === 'SHAM_CASH'" class="space-y-2 rounded bg-emerald-50 p-3 sm:col-span-2">
                                <p>{{ __('financial.ui.direct_collection_help') }}</p>
                                <x-text-input name="payment_reference" :value="old('payment_reference')" placeholder="{{ __('financial.ui.optional_sham_reference') }}" class="w-full" dir="ltr"/>
                            </div>
                            <x-text-input name="notes" :value="old('notes')" placeholder="{{ __('financial.ui.optional_notes') }}" class="sm:col-span-2" dir="auto"/>
                            <x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.record_collection') }}</x-primary-button>
                        </form>
                    @endif
                @endcan
                @if(!$invoice->collection && !$settledCorrection && !$invoice->superseded_at && ! app(\App\Services\InvoiceService::class)->retirementProblem($invoice->order))
                    @can('invoices.void')<form method="POST" action="{{ route('orders.invoices.void', [$invoice->order, $invoice]) }}" class="flex flex-wrap gap-2">@csrf<x-text-input name="reason" placeholder="{{ __('financial.ui.void_reason_required') }}" dir="auto"/><button class="app-button rounded bg-red-700 px-3 py-2 text-white">{{ __('financial.ui.void_invoice') }}</button></form>@endcan
                    @can('invoices.replace')<form method="POST" action="{{ route('orders.invoices.replace', [$invoice->order, $invoice]) }}" class="flex flex-wrap gap-2">@csrf<x-text-input name="reason" placeholder="{{ __('financial.ui.replace_reason_required') }}" dir="auto"/><button class="app-button rounded bg-amber-700 px-3 py-2 text-white">{{ __('financial.ui.create_replacement') }}</button></form>@endcan
                @endif
            @endif
        </section>

        @if(!$collectionAvailability['available'] && !$collectionAvailability['payment_satisfied'] && $invoice->status === \App\Enums\InvoiceStatus::Issued && !$invoice->superseded_at)<p class="rounded bg-amber-50 p-3 text-sm">{{ $collectionAvailability['reason'] }}</p>@endif
        @if($invoice->collection)
            <section class="rounded bg-green-50 p-5">
                <h3 class="font-semibold">{{ __('financial.ui.collected_full') }} · {{ $invoice->collection->collection_method ? \App\Support\UiLabels::label('financial.collection_methods', $invoice->collection->collection_method) : __('financial.ui.legacy_method') }}</h3>
                <p><x-money :amount="$invoice->collection->amount" /> <x-ltr>{{ $invoice->currency->code }}</x-ltr> · <x-ltr>{{ \App\Support\UiDate::datetime($invoice->collection->collected_at) }}</x-ltr></p>
                @if($invoice->collection->collection_method === \App\Enums\InvoiceCollectionMethod::Cash)
                    <p>{{ __('financial.ui.collector') }} {{ $invoice->collection->captain?->user?->name }}</p>
                @elseif($invoice->collection->collection_method === \App\Enums\InvoiceCollectionMethod::ShamCash)
                    <p>{{ __('financial.ui.direct_sham') }}</p>
                    @if($invoice->collection->payment_reference)<p>{{ __('financial.ui.reference') }}: <x-ltr>{{ $invoice->collection->payment_reference }}</x-ltr></p>@endif
                @endif
            </section>
        @endif
        @if($settledCorrection)
            <section class="rounded bg-blue-50 p-5">{{ __('financial.ui.settled_payment', ['id' => $settledCorrection->original_collection_id]) }}</section>
        @endif

        @if($invoice->collection && auth()->user()->can('invoice_corrections.view'))
            <section class="space-y-4 rounded bg-white p-5 shadow">
                <h3 class="font-semibold">{{ __('financial.ui.collected_correction') }}</h3>
                <p>{{ __('financial.ui.original_collection') }} <x-money :amount="$invoice->collection->amount" /> <x-ltr>{{ $invoice->currency->code }}</x-ltr> {{ __('financial.ui.via') }} {{ $invoice->collection->collection_method ? \App\Support\UiLabels::label('financial.collection_methods', $invoice->collection->collection_method) : __('financial.ui.legacy_method') }}{{ __('financial.ui.historical_facts') }}</p>
                @foreach($corrections as $correction)
                    <article class="space-y-2 rounded border p-4">
                        <p><strong>{{ \App\Support\UiLabels::label('financial.correction_kinds', $correction->kind) }}</strong> · {{ \App\Support\UiLabels::label('financial.correction_states', $correction->state) }} · {{ $correction->reason }}</p>
                        <p>{{ __('financial.ui.original') }} <x-money :amount="$correction->original_amount" /> · {{ __('financial.ui.corrected') }} @if($correction->corrected_amount !== null)<x-money :amount="$correction->corrected_amount" />@else{{ __('financial.ui.pending_approval') }}@endif · {{ __('financial.ui.customer_delta') }} @if($correction->customer_delta !== null)<x-money :amount="$correction->customer_delta" />@else{{ __('financial.ui.pending_approval') }}@endif <x-ltr>{{ $invoice->currency->code }}</x-ltr></p>
                        @foreach($correction->receivableDispositions as $disposition)
                            <p>{{ $captainNames->get($disposition->captain_id) ?: __('common.terms.captain') }} #<x-ltr>{{ $disposition->captain_id }}</x-ltr> · {{ \App\Support\UiLabels::label('financial.concepts', $disposition->concept) }}:
                                {{ __('financial.ui.disposition_original') }} <x-money :amount="$disposition->original_entitlement" /> · {{ __('financial.ui.disposition_paid') }} <x-money :amount="$disposition->historical_paid" /> ·
                                {{ __('financial.ui.disposition_corrected') }} <x-money :amount="$disposition->corrected_entitlement" /> · {{ __('financial.ui.disposition_retired') }} <x-money :amount="$disposition->original_unpaid" /> · {{ __('financial.ui.disposition_residual') }} <x-money :amount="$disposition->residual_payable" /> ·
                                {{ __('financial.ui.disposition_recovery') }} <x-money :amount="$disposition->recovery_required" /> <x-ltr>{{ $invoice->currency->code }}</x-ltr>.</p>
                            @if(\Brick\Math\BigDecimal::of($disposition->recovery_required)->isGreaterThan(0))
                                <p class="font-semibold text-red-700">{{ __('financial.ui.repayment_before_completion') }}</p>
                            @endif
                        @endforeach
                        @foreach($recoverySummaries[$correction->id] as $requirement)
                            <div class="rounded border p-3"><h4 class="font-semibold">{{ __('financial.terms.captain_recovery') }}</h4>
                                <p>{{ $requirement['captain_name'] }} ({{ __('common.terms.captain') }} #<x-ltr>{{ $requirement['captain_id'] }}</x-ltr>) · {{ $invoice->order->branch->name }} · {{ __('financial.ui.full_repayment') }} <x-money :amount="$requirement['amount']" /> <x-ltr>{{ $invoice->currency->code }}</x-ltr> · {{ $requirement['receipt'] ? __('financial.ui.received') : __('financial.ui.required') }}</p>
                                @foreach($requirement['dispositions'] as $source)<p>{{ \App\Support\UiLabels::label('financial.concepts', $source->concept) }}: <x-money :amount="$source->recovery_required" /></p>@endforeach
                                @if($requirement['receipt'])
                                    <p>{{ __('financial.ui.received_time') }} <x-ltr>{{ \App\Support\UiDate::datetime($requirement['receipt']->received_at) }}</x-ltr> {{ __('financial.ui.received_by') }} {{ $requirement['receipt']->receiver->name }}.</p>
                                    @can('captain_receivable_recoveries.view_evidence')<a class="underline" href="{{ route('captain-recoveries.evidence',[$correction,$requirement['receipt']]) }}">{{ __('financial.ui.repayment_evidence') }}</a>@endcan
                                @elseif($correction->state===\App\Enums\InvoiceFinancialCorrectionState::ReadyToFinalize && auth()->user()->roles()->where('code','ORDER_EMPLOYEE')->exists() && auth()->user()->branches()->whereKey($correction->branch_id)->exists())
                                    @can('captain_receivable_recoveries.record')
                                        <form method="POST" enctype="multipart/form-data" action="{{ route('captain-recoveries.store',[$correction,$requirement['captain_id']]) }}" class="grid gap-2 sm:grid-cols-2">
                                            @csrf
                                            <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                            <p>{{ __('financial.ui.exact_amount') }} <x-money :amount="$requirement['amount']" /> <x-ltr>{{ $invoice->currency->code }}</x-ltr>{{ __('financial.ui.repayment_help') }}</p>
                                            <label>{{ __('financial.ui.received_at') }} <x-text-input name="received_at" type="datetime-local" step="1" :value="now()->format('Y-m-d\TH:i:s')" required dir="ltr"/></label>
                                            <label>{{ __('financial.ui.reference') }} <x-text-input name="reference" dir="ltr"/></label>
                                            <label>{{ __('financial.ui.evidence_limit') }} <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required dir="auto"></label>
                                            <x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.record_repayment') }}</x-primary-button>
                                        </form>
                                    @endcan
                                @endif
                            </div>
                        @endforeach
                        @if($correction->kind === \App\Enums\InvoiceFinancialCorrectionKind::CancelAndRefund)
                            <p>{{ __('financial.ui.returns_before_finalize') }} <a class="underline" href="{{ route('orders.show', $invoice->order) }}">{{ __('financial.ui.view_order_progress') }}</a></p>
                        @endif
                        @if($correction->refund)
                            <p>{{ __('financial.ui.refund_completed') }} <x-money :amount="$correction->refund->amount" /> {{ __('financial.ui.via') }} {{ \App\Support\UiLabels::label('financial.collection_methods', $correction->refund->method) }}.
                                @if($correction->refund->refunding_captain_id) {{ __('financial.ui.historical_refund_captain') }} #{{ $correction->refund->refunding_captain_id }}. @else {{ __('financial.ui.company_refund') }} @endif
                                @can('customer_refunds.view_evidence')<a class="underline" href="{{ route('customer-refunds.evidence', $correction->refund) }}">{{ __('financial.ui.customer_refund_evidence') }}</a>@endcan
                            </p>
                            <p class="text-sm">{{ __('common.terms.currency') }}: <x-ltr>{{ $invoice->currency->code }}</x-ltr> · {{ __('financial.ui.refunded_at') }}: <x-ltr>{{ \App\Support\UiDate::datetime($correction->refund->refunded_at) }}</x-ltr> · {{ __('financial.ui.reference') }}: <x-ltr>{{ $correction->refund->reference }}</x-ltr> · {{ __('financial.ui.authorized_by') }}: <x-ltr>{{ $correction->refund->authorized_by }}</x-ltr></p>
                        @elseif($correction->state === \App\Enums\InvoiceFinancialCorrectionState::Prepared)
                            <p>{{ __('financial.ui.required_refund') }} <x-money :amount="(string) \Brick\Math\BigDecimal::of($correction->customer_delta)->negated()" /> {{ __('financial.ui.original_method') }} {{ $invoice->collection->collection_method ? \App\Support\UiLabels::label('financial.collection_methods', $invoice->collection->collection_method) : __('financial.ui.legacy_method') }}.
                                @if($invoice->collection->captain_id) {{ __('financial.ui.historical_refund_captain') }} #<x-ltr>{{ $invoice->collection->captain_id }}</x-ltr>. @endif
                            </p>
                            @can('customer_refunds.record')
                                <form method="POST" enctype="multipart/form-data" action="{{ route('invoice-corrections.refund', $correction) }}" class="grid gap-2 sm:grid-cols-2">
                                    @csrf
                                    <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <label>{{ __('financial.ui.refunded_at') }} <x-text-input name="refunded_at" type="datetime-local" step="1" :value="now()->format('Y-m-d\TH:i:s')" required dir="ltr"/></label>
                                    <label>{{ __('financial.ui.reference') }} <x-text-input name="reference" required dir="ltr"/></label>
                                    <label class="sm:col-span-2">{{ __('financial.ui.proof_limit') }} <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required dir="auto"></label>
                                    <x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.record_refund') }}</x-primary-button>
                                </form>
                            @endcan
                        @endif
                        @if($correction->state === \App\Enums\InvoiceFinancialCorrectionState::Requested)
                            @can('invoice_corrections.prepare')<form method="POST" action="{{ route('invoice-corrections.prepare', $correction) }}">@csrf<x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.prepare_correction') }}</x-primary-button></form>@endcan
                        @endif
                        @if(in_array($correction->state, [\App\Enums\InvoiceFinancialCorrectionState::Requested, \App\Enums\InvoiceFinancialCorrectionState::Prepared], true) && !$correction->refund)
                            @can('invoice_corrections.prepare')<form method="POST" action="{{ route('invoice-corrections.abandon', $correction) }}">@csrf<button class="app-button rounded bg-gray-700 px-3 py-2 text-white">{{ __('financial.ui.abandon_request') }}</button></form>@endcan
                        @endif
                        @if($correction->state === \App\Enums\InvoiceFinancialCorrectionState::ReadyToFinalize)
                            @can('invoice_corrections.finalize')
                                @if(collect($recoverySummaries[$correction->id])->contains(fn($r)=>!$r['receipt']))
                                    <p>{{ __('financial.ui.finalization_awaits') }}</p>
                                    <button disabled class="app-button rounded border p-2">{{ __('financial.ui.finalize_correction') }}</button>
                                @else
                                    <form method="POST" action="{{ route('invoice-corrections.finalize', $correction) }}">@csrf<x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.finalize_correction') }}</x-primary-button></form>
                                @endif
                            @endcan
                        @endif
                    </article>
                @endforeach
                @if($correctionSource && ! app(\App\Services\SharedPurchaseBoundary::class)->hasSelectedSharedContract($invoice->order) && !$corrections->contains(fn ($correction) => in_array($correction->state->value, ['REQUESTED','PREPARED','READY_TO_FINALIZE'], true)))
                    @can('invoice_corrections.request')
                        <details data-invoice-correction-request class="rounded-lg border p-3"><summary class="cursor-pointer font-semibold text-red-700">{{ __('financial.ui.request_correction') }}</summary>
                        <form method="POST" action="{{ route('invoices.corrections.request', $invoice) }}" class="space-y-3 rounded border p-4">
                            @csrf
                            <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <p>{{ __('financial.ui.request_help') }}</p>
                            <label>{{ __('financial.ui.kind') }} <select name="kind" class="min-w-0 max-w-full rounded border-gray-300"><option value="REPRICE">{{ __('financial.correction_kinds.REPRICE') }}</option><option value="CANCEL_AND_REFUND">{{ __('financial.correction_kinds.CANCEL_AND_REFUND') }}</option></select></label>
                            @foreach($invoice->items as $index => $item)
                                <label class="block">{{ $item->description }} · {{ __('financial.ui.corrected_unit_price') }}
                                    <input type="hidden" name="items[{{ $index }}][invoice_item_id]" value="{{ $item->id }}">
                                    <x-text-input dir="ltr" name="items[{{ $index }}][unit_price]" :value="$item->unit_price" required/>
                                </label>
                            @endforeach
                            @foreach($correctionSource['captain_allocations'] as $index => $allocation)
                                <label class="block">{{ $captainNames->get($allocation['captain_id']) ?: __('common.terms.captain') }} #<x-ltr>{{ $allocation['captain_id'] }}</x-ltr> · {{ __('financial.ui.corrected_delivery_allocation') }}
                                    <input type="hidden" name="delivery[{{ $index }}][order_captain_id]" value="{{ $allocation['order_captain_id'] }}">
                                    <x-text-input dir="ltr" name="delivery[{{ $index }}][customer_amount]" :value="$allocation['customer_delivery_amount_allocated']" required/>
                                </label>
                            @endforeach
                            <label class="block">{{ __('financial.ui.reason') }} <x-text-input name="reason" required class="w-full" dir="auto"/></label>
                            <label class="block">{{ __('financial.ui.explanation') }} <textarea name="explanation" required class="min-w-0 max-w-full w-full rounded border-gray-300"></textarea></label>
                            <x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.request_correction') }}</x-primary-button>
                        </form>
                        </details>
                    @endcan
                @endif
            </section>
        @endif
        @if($invoice->void_reason)<p class="rounded bg-red-50 p-4">{{ __('financial.ui.void_reason') }} {{ $invoice->void_reason }} · {{ $invoice->voider?->name }}</p>@endif
        @if($invoice->replacement_reason)<p class="rounded bg-amber-50 p-4">{{ __('financial.ui.replacement') }} {{ $invoice->replacement_reason }} · {{ $invoice->replacer?->name }} @if($invoice->replacementInvoice)<a class="underline" href="{{ route('orders.invoices.show', [$invoice->order, $invoice->replacementInvoice]) }}">{{ __('financial.ui.open_replacement') }}</a>@endif</p>@endif
    </div>
</x-app-layout>
