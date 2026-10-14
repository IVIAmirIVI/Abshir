<div class="min-w-0 space-y-4" data-captain-financial-history>
    <h3 class="text-lg font-semibold">{{ __('captain_dashboard.movements') }}</h3>
    @can('captain_receivable_payouts.view')
        <section id="captain-payout-history" tabindex="-1" class="space-y-3" aria-labelledby="captain-payout-history-title">
            <h4 id="captain-payout-history-title" class="font-semibold">{{ __('financial.ui.payout_history') }}</h4>
            @forelse($payouts as $payout)
                <article class="min-w-0 space-y-2 rounded-xl border border-stone-200 bg-white p-4 text-sm">
                    <div class="flex min-w-0 flex-wrap justify-between gap-2"><p class="font-semibold">{{ __('financial.terms.receivable_payout') }}</p><p class="captain-balance-value font-bold"><x-money :amount="$payout->amount" :currency="$payout->currency->code" /></p></div>
                    <p class="text-gray-600">{{ $payout->branch->name }} · <x-ltr>{{ \App\Support\UiDate::datetime($payout->occurred_at) }}</x-ltr>@if($payout->payment_method) · {{ __('captain_requests.methods.'.$payout->payment_method) }}@endif</p>
                    <ul class="space-y-1">@foreach($payout->allocations as $allocation)<li><x-financial.receivable-label :event-type="$allocation->financialEntry?->transaction?->event_type" :paid="true" />: <x-money :amount="$allocation->amount" :currency="$payout->currency->code" /></li>@endforeach</ul>
                    @can('captain_financial_evidence.download')<a class="link inline-block" href="{{ route('captains.financials.payouts.evidence', [$captain, $payout]) }}">{{ __('financial.ui.evidence') }}</a>@endcan
                    <details class="text-gray-600"><summary class="cursor-pointer">{{ __('financial.ui.attribution_details') }}</summary><div class="mt-2 space-y-1">
                        <p>{{ __('financial.ui.recorded_by') }}: {{ $payout->paidBy->name }}@if($payout->receivedBy) · {{ __('captain_requests.received_by') }}: {{ $payout->receivedBy->name }}@endif</p>
                        <p>{{ __('financial.ui.reference') }}: <x-ltr>{{ $payout->reference ?: '—' }}</x-ltr></p>
                        @foreach($payout->allocations as $allocation)<p>{{ __('financial.ui.receivable_entry') }} <x-ltr>#{{ $allocation->financial_entry_id }}</x-ltr> · {{ \App\Support\UiLabels::label('financial.events', $allocation->financialEntry?->transaction?->event_type ?? '') }}</p>@endforeach
                    </div></details>
                </article>
            @empty<p class="rounded-lg bg-white p-4 text-sm">{{ __('financial.ui.no_payouts') }}</p>@endforelse
        </section>
    @endcan
    @can('captain_deposits.view')
        <section class="space-y-3" aria-labelledby="captain-deposit-history-title">
            <h4 id="captain-deposit-history-title" class="font-semibold">{{ __('financial.ui.deposit_history') }}</h4>
            @forelse($receipts as $receipt)
                <article class="min-w-0 space-y-2 rounded-xl border border-stone-200 bg-white p-4 text-sm">
                    <div class="flex min-w-0 flex-wrap justify-between gap-2"><p class="font-semibold">{{ __('financial.ui.deposit_received') }}</p><p class="captain-balance-value font-bold"><x-money :amount="$receipt->amount" :currency="$receipt->currency->code" /></p></div>
                    <p class="text-gray-600">{{ $receipt->branch->name }} · <x-ltr>{{ \App\Support\UiDate::datetime($receipt->occurred_at) }}</x-ltr> · {{ __('financial.ui.received_by') }} {{ $receipt->receivedBy->name }}</p>
                    @can('captain_financial_evidence.download')<a class="link inline-block" href="{{ route('captains.financials.deposits.evidence', [$captain, $receipt]) }}">{{ __('financial.ui.evidence') }}</a>@endcan
                    <details class="text-gray-600"><summary class="cursor-pointer">{{ __('financial.ui.attribution_details') }}</summary><p class="mt-2">{{ __('financial.ui.reference') }}: <x-ltr>{{ $receipt->reference ?: '—' }}</x-ltr></p></details>
                </article>
            @empty<p class="rounded-lg bg-white p-4 text-sm">{{ __('financial.ui.no_receipts') }}</p>@endforelse
        </section>
    @endcan
</div>
