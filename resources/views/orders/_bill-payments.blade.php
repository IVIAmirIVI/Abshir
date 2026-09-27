@can('bill_payments.view')
<section class="rounded bg-white p-5 shadow space-y-4">
    <div class="flex flex-wrap justify-between gap-3">
        <h3 class="font-semibold">خدمات إضافية / دفع الفواتير</h3>
        <span class="text-sm">{{ $order->additionalServicesComplete() ? 'Additional services complete' : 'Bill payment pending' }}</span>
    </div>
    @can('bill_payments.record')
        @if(!in_array($order->operational_status, [\App\Enums\OrderOperationalStatus::Delivered, \App\Enums\OrderOperationalStatus::Cancelled], true))
            <form method="POST" action="{{ route('orders.bill-payment-requests.store', $order) }}" class="flex flex-wrap gap-2 items-center">
                @csrf
                <select name="currency_id" class="rounded border-gray-300" required>
                    @foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach
                </select>
                <x-primary-button>Request bill-payment service</x-primary-button>
            </form>
        @endif
    @endcan
    @foreach($order->billPaymentRequests as $billRequest)
        <article class="rounded border p-4 space-y-3">
            <div class="flex flex-wrap justify-between gap-2"><strong>Request #{{ $billRequest->id }} · {{ $billRequest->status->value }}</strong><span>Fixed fee: {{ $billRequest->service_fee }} {{ $billRequest->currency->code }}</span></div>
            @if($billRequest->status === \App\Enums\OrderBillPaymentRequestStatus::Requested)
                @can('bill_payments.record')
                    @if($order->operational_status === \App\Enums\OrderOperationalStatus::Delivered)
                        <form method="POST" enctype="multipart/form-data" action="{{ route('orders.bill-payment-requests.complete', [$order, $billRequest]) }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @csrf
                            <input type="hidden" name="currency_id" value="{{ $billRequest->currency_id }}">
                            <select name="order_captain_id" class="rounded border-gray-300" required>
                                <option value="">Collecting Captain</option>
                                @foreach($order->captainAssignments->whereNull('removed_at') as $assignment)
                                    <option value="{{ $assignment->id }}">{{ $assignment->captain->user->name }} · {{ $assignment->assignment_role->label() }}</option>
                                @endforeach
                            </select>
                            <x-text-input name="bill_number" placeholder="Bill number" required />
                            <x-text-input name="bill_date" type="date" required />
                            <x-text-input name="provider_name" placeholder="Provider (optional)" />
                            <x-text-input name="principal_amount" placeholder="Bill principal" required />
                            <x-text-input name="payment_reference" placeholder="Sham Cash payment reference" required />
                            <input type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple required class="rounded border-gray-300">
                            <p class="text-sm">Collect principal + {{ $billRequest->service_fee }} {{ $billRequest->currency->code }}. Record only after successful external payment and principal handover.</p>
                            <x-primary-button>Record successful payment</x-primary-button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('orders.bill-payment-requests.cancel', [$order, $billRequest]) }}" class="flex flex-wrap gap-2">
                        @csrf
                        <x-text-input name="reason" placeholder="Cancellation reason" required />
                        <x-primary-button>Cancel request</x-primary-button>
                    </form>
                @endcan
            @elseif($billRequest->status === \App\Enums\OrderBillPaymentRequestStatus::Completed && $billRequest->payment)
                @can('bill_payments.view')
                    @php($bill = $billRequest->payment)
                    <p class="text-sm">{{ $bill->bill_number }} · {{ $bill->bill_date->format('Y-m-d') }} · Principal {{ $bill->principal_amount }} + fee {{ $bill->service_fee }} = {{ $bill->total_customer_cash }} {{ $billRequest->currency->code }}</p>
                    <p class="text-sm">Captain: {{ $bill->orderCaptain->captain->user->name }} · Employee: {{ $bill->paidBy?->name ?? 'Former employee' }} · Sham Cash reference: {{ $bill->payment_reference }} · Paid {{ $bill->paid_at->format('Y-m-d H:i') }}</p>
                    <div class="flex flex-wrap gap-3">@foreach($bill->evidence as $image)<a class="underline" href="{{ route('orders.bill-payments.evidence.show', [$order, $billRequest, $bill, $image]) }}">Payment evidence #{{ $image->id }}</a>@endforeach</div>
                @endcan
            @else
                <p class="text-sm">Cancelled {{ $billRequest->cancelled_at?->format('Y-m-d H:i') }} · {{ $billRequest->cancellation_reason }}</p>
            @endif
        </article>
    @endforeach
</section>
@endcan
