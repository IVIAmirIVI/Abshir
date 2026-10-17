<div class="financial-ui space-y-4">
@can('invoices.view')
    @include('orders._invoices', ['summaryOnly' => true])
    @if($workflow['invoiceProblem'])<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $workflow['invoiceProblem'] }}</p>@endif
    @if($workflow['canCreateInvoice'])<x-orders.action type="button" wire:click="createInvoice" wire:loading.attr="disabled">{{ __('financial.ui.create_draft') }}</x-orders.action>@endif
    @if($workflow['canRefreshInvoice'])@if(!$order->currentInvoice || $order->currentInvoice->status !== \App\Enums\InvoiceStatus::Ready || $workflow['stale'])<x-orders.action type="button" wire:click="refreshInvoice" wire:loading.attr="disabled">{{ __('financial.ui.refresh_ready') }}</x-orders.action>@endif @endif
    @if($workflow['canIssueInvoice'])<x-orders.action type="button" wire:click="issueInvoice" wire:loading.attr="disabled">{{ __('financial.ui.issue_invoice') }}</x-orders.action>@endif
    @if($order->currentInvoice?->status === \App\Enums\InvoiceStatus::Issued)<p class="text-sm text-gray-600">{{ __('orders.workflow.invoice_help') }}</p>@endif
    @if($workflow['availability']['settled_correction'] ?? false)<p class="rounded-lg bg-green-50 p-3">{{ __('orders.ui_payment_settled_correction') }}</p>@endif
    @if($workflow['canCollect'])
        <form wire:submit="collectInvoice" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2">
            <p class="sm:col-span-2">{{ __('financial.ui.full_collection') }} <strong><x-money :amount="$order->currentInvoice->grand_total" /> <x-ltr>{{ $order->currentInvoice->currency?->code }}</x-ltr></strong></p>
            @if($creditGrants->isNotEmpty())
                <fieldset class="min-w-0 space-y-3 rounded-lg border border-stone-200 p-3 sm:col-span-2" data-credit-settlement>
                    <legend class="font-semibold">{{ __('credit.delivery_settlement') }}</legend>
                    <p class="text-sm text-gray-600">{{ __('credit.workflow_help') }}</p>
                    @foreach($creditGrants as $grant)
                        <label class="block" wire:key="delivery-credit-grant-{{ $grant->id }}">{{ __('credit.available') }}: <x-decimal :value="$grant->available" /> · {{ __('credit.'.$grant->reason) }} · {{ $grant->reference }}
                            <span class="block text-sm">{{ __('credit.use_amount') }}</span><input type="text" inputmode="decimal" dir="ltr" wire:model.live.blur="creditAllocations.{{ $grant->id }}" class="mt-1 w-full rounded-lg border-gray-300" placeholder="0">
                        </label>
                        @if(($grant->responsibilities ?? collect())->count() > 1)
                            <fieldset class="min-w-0 space-y-2 rounded-lg border p-3" wire:key="credit-parties-{{ $grant->id }}">
                                <legend>{{ __('credit.attribution_title') }}</legend>
                                <p class="text-sm">{{ __('credit.attribution_help') }}</p>
                                @foreach($grant->responsibilities as $party)
                                    <label class="block text-sm">{{ __('returns.'.$party->party) }} {{ $party->name }} · {{ __('credit.attributable_remaining') }}: <x-decimal :value="$party->available" />
                                        <input type="text" inputmode="decimal" dir="ltr" wire:model="creditAttributions.{{ $grant->id }}.{{ $party->id }}" class="mt-1 w-full rounded-lg border-gray-300" placeholder="0">
                                    </label>
                                @endforeach
                            </fieldset>
                        @endif
                    @endforeach
                    @if($creditExpectedActual !== null)<p>{{ __('credit.expected_actual') }}: <x-decimal :value="$creditExpectedActual" /> · {{ __('credit.redeemed') }}: <x-decimal :value="(string) $creditTotal" /></p>@endif
                    <label class="block">{{ __('credit.actual_payment') }}<input type="text" inputmode="decimal" dir="ltr" wire:model="collectionActualAmount" class="mt-1 w-full rounded-lg border-gray-300" placeholder="{{ $creditExpectedActual }}"></label>
                    @error('credit')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                    @error('collectionActualAmount')<p role="alert" class="text-sm text-red-700">{{ __('credit.settlement_invalid') }}</p>@enderror
                </fieldset>
            @endif
            <label>{{ __('financial.ui.collection_method') }}<select aria-invalid="{{ $errors->has('collectionMethod') ? 'true' : 'false' }}" wire:model.live="collectionMethod" class="mt-1 w-full rounded-lg border-gray-300"><option value="CASH" @disabled(!$workflow['primary'])>{{ __('financial.collection_methods.CASH') }}</option><option value="SHAM_CASH">{{ __('financial.collection_methods.SHAM_CASH') }}</option></select></label>
            <label>{{ __('financial.ui.collected_at') }}<input aria-invalid="{{ $errors->has('collectedAt') ? 'true' : 'false' }}" wire:model="collectedAt" type="datetime-local" step="1" dir="ltr" required class="mt-1 w-full rounded-lg border-gray-300"></label>
            @if($collectionMethod === 'CASH')<p class="sm:col-span-2">{{ __('financial.ui.collector') }} {{ $workflow['primary']?->captain?->user?->name ?? __('financial.ui.no_collector') }}</p>@else<p class="sm:col-span-2">{{ __('financial.ui.direct_collection_help') }}</p><label>{{ __('financial.ui.optional_sham_reference') }}<input aria-invalid="{{ $errors->has('paymentReference') ? 'true' : 'false' }}" wire:model="paymentReference" dir="ltr" class="mt-1 w-full rounded-lg border-gray-300"></label>@endif
            <label>{{ __('common.fields.notes') }}<input aria-invalid="{{ $errors->has('collectionNotes') ? 'true' : 'false' }}" wire:model="collectionNotes" dir="auto" class="mt-1 w-full rounded-lg border-gray-300"></label>
            <x-orders.action wire:loading.attr="disabled" :disabled="$collectionMethod === 'CASH' && !$workflow['primary']">{{ __('financial.ui.record_collection') }}</x-orders.action>
        </form>
    @endif
@else<p>{{ __('orders.workflow.readonly') }}</p>@endcan
</div>
