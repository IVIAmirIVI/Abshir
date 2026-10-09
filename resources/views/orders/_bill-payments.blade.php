@can('bill_payments.view')
<section class="rounded bg-white p-5 shadow space-y-4">
    <div class="flex flex-wrap justify-between gap-3">
        <h3 class="font-semibold">{{ __('orders.ui_bill_title') }}</h3>
        <span class="text-sm" data-bill-payment-state>
            @if($order->billPaymentRequests->isEmpty()){{ __('orders.ui_bill_not_requested') }}
            @elseif($order->billPaymentRequests->contains(fn ($request) => $request->status === \App\Enums\OrderBillPaymentRequestStatus::Requested)){{ __('orders.ui_bill_pending') }}
            @elseif($order->billPaymentRequests->contains(fn ($request) => $request->status === \App\Enums\OrderBillPaymentRequestStatus::Completed)){{ __('orders.ui_additional_complete') }}
            @else{{ __('orders.ui_bill_all_cancelled') }}@endif
        </span>
    </div>
    @php($requestCurrencies = $billPaymentCurrencies)
    @if($summaryOnly ?? false)
        <a href="{{ route('orders.workflow', $order) }}#workflow-bill-payments" class="inline-block text-sm underline">{{ __('orders.workflow.return') }}</a>
    @else
    @can('bill_payments.record')
        @if($billPaymentRequestProblem)
            <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm" data-bill-payment-unavailable>{{ $billPaymentRequestProblem }}</p>
            @if($requestCurrencies->isEmpty())@can('bill_payment_fees.manage')<a href="{{ route('admin.bill-payment-fees.index') }}" class="inline-block text-sm underline">{{ __('orders.ui_bill_configure_fees') }}</a>@endcan @endif
        @else
            <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.bill-payment-requests.store', $order) }}" class="flex flex-wrap gap-2 items-center">
                @csrf
                <select name="currency_id" class="min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300" required>
                    @foreach($requestCurrencies as $currency)<option value="{{ $currency->id }}">{{ "\u{2066}".$currency->code."\u{2069}" }}</option>@endforeach
                </select>
                <x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_request_bill') }}</x-primary-button>
            </form>
        @endif
    @endcan
    @endif
    <p class="text-sm text-gray-600">{{ __('orders.ui_bill_request_help') }}</p>
    @foreach($order->billPaymentRequests as $billRequest)
        <article class="rounded border p-4 space-y-3">
            <div class="flex flex-wrap justify-between gap-2"><strong>{{ __('orders.ui_request_id') }}<x-ltr>{{ $billRequest->id }}</x-ltr> · {{ \App\Support\UiLabels::label('orders.bill_statuses', $billRequest->status) }}</strong><span>{{ __('bill_fees.service_fee') }} @if($billRequest->service_fee !== null || $billRequest->payment)<x-money :amount="$billRequest->payment?->service_fee ?? $billRequest->service_fee" /> <x-ltr>{{ $billRequest->currency->code }}</x-ltr>@else{{ __('bill_fees.calculated_later') }}@endif</span></div>
            @if($billRequest->status === \App\Enums\OrderBillPaymentRequestStatus::Requested)
                @if(!($summaryOnly ?? false))@can('bill_payments.record')
                    @if(!$order->cancellationIntent && $order->operational_status === \App\Enums\OrderOperationalStatus::Delivered)
                        <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" enctype="multipart/form-data" action="{{ route('orders.bill-payment-requests.complete', [$order, $billRequest]) }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @csrf
                            <input type="hidden" name="currency_id" value="{{ $billRequest->currency_id }}">
                            @if($sharedFinancialSelected)
                                @php($billCollector = $order->captainAssignments->first(fn ($assignment) => $assignment->removed_at === null && $assignment->assignment_role === \App\Enums\OrderCaptainRole::PrimaryCollection))
                                <input type="hidden" name="order_captain_id" value="{{ $billCollector?->id }}">
                                <p class="rounded bg-stone-50 p-3 text-sm">{{ __('shared_orders.bill_selected_collector') }}: <strong>{{ $billCollector?->captain?->user?->name }}</strong></p>
                            @else
                            <select name="order_captain_id" class="min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300" required>
                                <option value="">{{ __('orders.ui_collecting_captain') }}</option>
                                @foreach($order->captainAssignments->whereNull('removed_at') as $assignment)
                                    <option value="{{ $assignment->id }}">{{ $assignment->captain->user->name }} · {{ \App\Support\UiLabels::label('orders.captain_roles', $assignment->assignment_role) }}</option>
                                @endforeach
                            </select>
                            @endif
                            <x-text-input name="bill_number" placeholder="{{ __('orders.ui_bill_number') }}" required  dir="ltr"/><x-input-error :messages="$errors->get('bill_number')" />
                            <x-text-input name="bill_date" type="date" required  dir="ltr"/><x-input-error :messages="$errors->get('bill_date')" />
                            <x-text-input name="provider_name" placeholder="{{ __('orders.ui_provider') }}"  dir="auto"/><x-input-error :messages="$errors->get('provider_name')" />
                            @livewire('orders.bill-payment-fee-preview', ['order' => $order, 'request' => $billRequest], key('bill-fee-preview-'.$billRequest->id))
                            <x-text-input name="payment_reference" placeholder="{{ __('orders.ui_sham_reference') }}" required  dir="ltr"/><x-input-error :messages="$errors->get('payment_reference')" />
                            <input type="file" class="max-w-full min-w-0 max-w-full w-full sm:w-auto rounded border-gray-300" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple required>
                            <p class="text-sm">{{ __('orders.ui_record_after_payment') }}</p>
                            <x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_record_payment') }}</x-primary-button>
                        </form>
                    @endif
                    <form x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('orders.bill-payment-requests.cancel', [$order, $billRequest]) }}" class="flex flex-wrap gap-2">
                        @csrf
                        <x-text-input name="reason" placeholder="{{ __('orders.ui_cancel_reason') }}" required  dir="auto"/><x-input-error :messages="$errors->get('reason')" />
                        <x-primary-button x-bind:disabled="submitting">{{ __('orders.ui_cancel_request') }}</x-primary-button>
                    </form>
                @endcan @endif
            @elseif($billRequest->status === \App\Enums\OrderBillPaymentRequestStatus::Completed && $billRequest->payment)
                @can('bill_payments.view')
                    @php($bill = $billRequest->payment)
                    <p class="text-sm"><x-ltr>{{ $bill->bill_number }}</x-ltr> · <x-ltr>{{ $bill->bill_date->format('Y-m-d') }}</x-ltr> · {{ __('orders.ui_principal_label') }} <x-money :amount="$bill->principal_amount" /> {{ __('orders.ui_plus_fee') }} <x-money :amount="$bill->service_fee" /> = <x-money :amount="$bill->total_customer_cash" /> <x-ltr>{{ $billRequest->currency->code }}</x-ltr></p>
                    <p class="text-sm">{{ __('orders.ui_captain_label') }} {{ $bill->orderCaptain->captain->user->name }} · {{ __('orders.ui_employee_label') }} {{ $bill->paidBy?->name ?? __('orders.ui_former_employee') }} · {{ __('orders.ui_sham_reference_label') }} <x-ltr>{{ $bill->payment_reference }}</x-ltr> · {{ __('orders.ui_paid') }} <x-ltr>{{ $bill->paid_at->format('Y-m-d H:i') }}</x-ltr></p>
                    <div class="flex flex-wrap gap-3">@foreach($bill->evidence as $image)<a class="underline" href="{{ route('orders.bill-payments.evidence.show', [$order, $billRequest, $bill, $image]) }}">{{ __('orders.ui_payment_evidence') }}<x-ltr>{{ $image->id }}</x-ltr></a>@endforeach</div>
                @endcan
            @else
                <p class="text-sm">{{ __('orders.ui_cancelled') }} <x-ltr>{{ $billRequest->cancelled_at?->format('Y-m-d H:i') }}</x-ltr> · {{ $billRequest->cancellation_reason }}</p>
            @endif
        </article>
    @endforeach
</section>
@endcan
