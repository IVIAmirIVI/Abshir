<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $captain->user->name }} · {{ __('financial.ui.branch_financials') }}</h2></x-slot>
    <div class="financial-ui max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-7 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="rounded-lg bg-white p-5 shadow space-y-3">
            <h3 class="text-lg font-semibold">{{ __('financial.ui.independent_balances') }}</h3>
            <p class="text-sm text-gray-600">{{ __('financial.ui.exact_balances_help') }}</p>
            <x-financial.account-guide />
            <x-financial.table :label="__('financial.ui.independent_balances')"><table class="min-w-full text-sm text-start"><thead><tr class="border-b"><th class="p-2">{{ __('common.terms.branch') }}</th><th class="p-2">{{ __('common.terms.currency') }}</th><th class="p-2">{{ __('financial.accounts.CAPTAIN_DEPOSIT') }}</th><th class="p-2">{{ __('financial.accounts.CAPTAIN_COMPANY_RECEIVABLE') }}</th><th class="p-2">{{ __('financial.accounts.CAPTAIN_CASH_CUSTODY') }}</th></tr></thead><tbody>
                @forelse($rows as $row)<tr class="border-b"><td class="p-2">{{ $row['branch']->name }}</td><td class="p-2"><x-ltr>{{ $row['currency']->code }}</x-ltr></td><td class="p-2"><x-money :amount="$row['values']['CAPTAIN_DEPOSIT']" /></td><td class="p-2"><x-money :amount="$row['values']['CAPTAIN_COMPANY_RECEIVABLE']" /></td><td class="p-2"><x-money :amount="$row['values']['CAPTAIN_CASH_CUSTODY']" /></td></tr>
                @empty<tr><td colspan="5" class="p-2">{{ __('financial.ui.no_branch_history') }}</td></tr>@endforelse
            </tbody></table></x-financial.table>
        </section>

        @if($manualAdjustmentsEnabled)
            @include('captain-financials.manual-deposit-adjustments')
        @endif

        @can('captain_deposits.create')
        <section class="rounded-lg bg-white p-5 shadow space-y-3"><h3 class="text-lg font-semibold">{{ __('financial.terms.deposit_receipt') }}</h3>
            <form method="POST" enctype="multipart/form-data" action="{{ route('captains.financials.deposits.store', $captain) }}" class="grid gap-3 sm:grid-cols-2">@csrf
                <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label>{{ __('common.terms.branch') }}<select name="branch_id" required class="block w-full rounded-md border-gray-300">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <label>{{ __('common.terms.currency') }}<select name="currency_id" required class="block w-full rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ "\u{2066}".($currency->code)."\u{2069}" }}</option>@endforeach</select></label>
                <label>{{ __('financial.ui.amount') }}<input type="text" inputmode="decimal" name="amount" required class="block w-full rounded-md border-gray-300" dir="ltr"></label>
                <label>{{ __('financial.ui.occurred_at') }}<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="block w-full rounded-md border-gray-300" dir="ltr"></label>
                <label>{{ __('financial.ui.reference') }}<input type="text" name="reference" class="block w-full rounded-md border-gray-300" dir="ltr"></label>
                <label>{{ __('financial.ui.evidence_image') }}<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full" dir="auto"></label>
                <label class="sm:col-span-2">{{ __('financial.ui.note') }}<textarea name="note" class="block w-full rounded-md border-gray-300"></textarea></label>
                <div class="sm:col-span-2"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.record_deposit') }}</x-primary-button></div>
            </form>
        </section>
        @endcan

        @can('captain_receivable_payouts.create')
        <section class="rounded-lg bg-white p-5 shadow space-y-4"><h3 class="text-lg font-semibold">{{ __('financial.ui.pay_attributed') }}</h3>
            <form method="GET" action="{{ route('captains.financials.show', $captain) }}" class="flex flex-wrap items-end gap-3"><label>{{ __('common.terms.branch') }}<select name="branch_id" class="min-w-0 max-w-full block w-full rounded-md border-gray-300">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($selectedBranch?->id === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label><label>{{ __('common.terms.currency') }}<select name="currency_id" class="min-w-0 max-w-full block w-full rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected($selectedCurrency?->id === $currency->id)>{{ "\u{2066}".($currency->code)."\u{2069}" }}</option>@endforeach</select></label><button class="app-button w-full sm:w-auto rounded bg-gray-800 px-3 py-2 text-white">{{ __('financial.ui.show_sources') }}</button></form>
            @if($selectedBranch && $selectedCurrency)
                <p class="text-sm text-gray-600">{{ __('financial.ui.allocation_help') }}</p>
                <form method="POST" enctype="multipart/form-data" action="{{ route('captains.financials.payouts.store', $captain) }}" class="space-y-4">@csrf
                    <input type="hidden" name="branch_id" value="{{ $selectedBranch->id }}"><input type="hidden" name="currency_id" value="{{ $selectedCurrency->id }}"><input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <x-financial.table :label="__('financial.ui.pay_attributed')"><table class="min-w-full text-sm text-start"><thead><tr class="border-b"><th class="p-2">{{ __('financial.ui.source_order') }}</th><th class="p-2">{{ __('financial.ui.occurred') }}</th><th class="p-2">{{ __('financial.ui.meaning') }}</th><th class="p-2">{{ __('financial.ui.original') }}</th><th class="p-2">{{ __('financial.ui.already_paid') }}</th><th class="p-2">{{ __('financial.ui.remaining') }}</th><th class="p-2">{{ __('financial.ui.this_payout') }}</th></tr></thead><tbody>
                        @forelse($outstanding as $entry)<tr class="border-b"><td class="p-2">#<x-ltr>{{ $entry->financial_entry_id }}</x-ltr> · <x-ltr>{{ $entry->order_number ?: '—' }}</x-ltr></td><td class="p-2"><x-ltr>{{ \App\Support\UiDate::datetime($entry->occurred_at) }}</x-ltr></td><td class="p-2">{{ \App\Support\UiLabels::label('financial.events', $entry->event_type) }}</td><td class="p-2"><x-money :amount="$entry->amount" /></td><td class="p-2"><x-money :amount="$entry->paid_amount" /></td><td class="p-2"><x-money :amount="$entry->remaining_amount" /></td><td class="p-2"><input type="hidden" name="allocations[{{ $entry->financial_entry_id }}][financial_entry_id]" value="{{ $entry->financial_entry_id }}"><input aria-label="{{ __('financial.ui.this_payout') }} — {{ $entry->order_number ?: $entry->financial_entry_id }}" class="w-28 rounded-md border-gray-300" type="text" inputmode="decimal" name="allocations[{{ $entry->financial_entry_id }}][amount]" placeholder="0.00" dir="ltr"></td></tr>
                        @empty<tr><td colspan="7" class="p-2">{{ __('financial.ui.no_sources') }}</td></tr>@endforelse
                    </tbody></table></x-financial.table>
                    <div class="grid gap-3 sm:grid-cols-2"><label>{{ __('financial.ui.total_amount') }}<input type="text" inputmode="decimal" name="amount" required class="block w-full rounded-md border-gray-300" dir="ltr"></label><label>{{ __('financial.ui.occurred_at') }}<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="block w-full rounded-md border-gray-300" dir="ltr"></label><label>{{ __('financial.ui.payment_reference') }}<input type="text" name="reference" class="block w-full rounded-md border-gray-300" dir="ltr"></label><label>{{ __('financial.ui.evidence_image') }}<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full" dir="auto"></label><label class="sm:col-span-2">{{ __('financial.ui.note') }}<textarea name="note" class="block w-full rounded-md border-gray-300"></textarea></label></div>
                    <x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.record_payout') }}</x-primary-button>
                </form>
            @endif
        </section>
        @endcan

        <div class="grid gap-5 lg:grid-cols-2">
            @can('captain_deposits.view')<section class="rounded-lg bg-white p-5 shadow"><h3 class="font-semibold">{{ __('financial.ui.deposit_history') }}</h3>@forelse($receipts as $receipt)<p class="border-b py-2 text-sm"><x-ltr>{{ \App\Support\UiDate::datetime($receipt->occurred_at) }}</x-ltr> · {{ $receipt->branch->name }} · <x-money :amount="$receipt->amount" /> <x-ltr>{{ $receipt->currency->code }}</x-ltr> · {{ __('financial.ui.received_by') }} {{ $receipt->receivedBy->name }} · <x-ltr>{{ $receipt->reference ?: '—' }}</x-ltr> @can('captain_financial_evidence.download')<a class="underline" href="{{ route('captains.financials.deposits.evidence', [$captain, $receipt]) }}">{{ __('financial.ui.evidence') }}</a>@endcan</p>@empty<p class="text-sm">{{ __('financial.ui.no_receipts') }}</p>@endforelse</section>@endcan
            @can('captain_receivable_payouts.view')<section class="rounded-lg bg-white p-5 shadow"><h3 class="font-semibold">{{ __('financial.ui.payout_history') }}</h3>@forelse($payouts as $payout)<div class="border-b py-2 text-sm"><p><x-ltr>{{ \App\Support\UiDate::datetime($payout->occurred_at) }}</x-ltr> · {{ $payout->branch->name }} · <x-money :amount="$payout->amount" /> <x-ltr>{{ $payout->currency->code }}</x-ltr> · {{ $payout->paidBy->name }} · <x-ltr>{{ $payout->reference ?: '—' }}</x-ltr> @can('captain_financial_evidence.download')<a class="underline" href="{{ route('captains.financials.payouts.evidence', [$captain, $payout]) }}">{{ __('financial.ui.evidence') }}</a>@endcan</p><ul class="list-disc ps-5 text-gray-600">@foreach($payout->allocations as $allocation)<li>{{ __('financial.ui.receivable_entry') }} #<x-ltr>{{ $allocation->financial_entry_id }}</x-ltr>: <x-money :amount="$allocation->amount" /></li>@endforeach</ul></div>@empty<p class="text-sm">{{ __('financial.ui.no_payouts') }}</p>@endforelse</section>@endcan
        </div>
        <a class="underline text-sm" href="{{ route('captain-financials.index') }}">{{ __('financial.ui.captain_history') }}</a>
    </div>
</x-app-layout>
