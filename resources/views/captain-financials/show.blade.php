<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $captain->user->name }} · Branch financials</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-7 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="rounded-lg bg-white p-5 shadow space-y-3">
            <h3 class="text-lg font-semibold">Independent Branch and Currency balances</h3>
            <p class="text-sm text-gray-600">Amounts are exact ledger sums. No account netting or automatic transfer occurs.</p>
            <div class="overflow-x-auto"><table class="min-w-full text-sm text-left"><thead><tr class="border-b"><th class="p-2">Branch</th><th class="p-2">Currency</th><th class="p-2">Operational Deposit</th><th class="p-2">Abshir owes Captain</th><th class="p-2">Cash Custody</th></tr></thead><tbody>
                @forelse($rows as $row)<tr class="border-b"><td class="p-2">{{ $row['branch']->name }}</td><td class="p-2">{{ $row['currency']->code }}</td><td class="p-2">{{ $row['values']['CAPTAIN_DEPOSIT'] }}</td><td class="p-2">{{ $row['values']['CAPTAIN_COMPANY_RECEIVABLE'] }}</td><td class="p-2">{{ $row['values']['CAPTAIN_CASH_CUSTODY'] }}</td></tr>
                @empty<tr><td colspan="5" class="p-2">No financial history in accessible Branches.</td></tr>@endforelse
            </tbody></table></div>
        </section>

        @if($manualAdjustmentsEnabled)
            @include('captain-financials.manual-deposit-adjustments')
        @endif

        @can('captain_deposits.create')
        <section class="rounded-lg bg-white p-5 shadow space-y-3"><h3 class="text-lg font-semibold">Record real Captain Deposit receipt</h3>
            <form method="POST" enctype="multipart/form-data" action="{{ route('captains.financials.deposits.store', $captain) }}" class="grid gap-3 sm:grid-cols-2">@csrf
                <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label>Branch<select name="branch_id" required class="block w-full rounded-md border-gray-300">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <label>Currency<select name="currency_id" required class="block w-full rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></label>
                <label>Amount<input type="text" inputmode="decimal" name="amount" required class="block w-full rounded-md border-gray-300"></label>
                <label>Occurred at<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="block w-full rounded-md border-gray-300"></label>
                <label>Reference<input type="text" name="reference" class="block w-full rounded-md border-gray-300"></label>
                <label>Evidence image<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full"></label>
                <label class="sm:col-span-2">Note<textarea name="note" class="block w-full rounded-md border-gray-300"></textarea></label>
                <div class="sm:col-span-2"><x-primary-button>Record Deposit receipt</x-primary-button></div>
            </form>
        </section>
        @endcan

        @can('captain_receivable_payouts.create')
        <section class="rounded-lg bg-white p-5 shadow space-y-4"><h3 class="text-lg font-semibold">Pay attributed Captain Receivable</h3>
            <form method="GET" action="{{ route('captains.financials.show', $captain) }}" class="flex flex-wrap items-end gap-3"><label>Branch<select name="branch_id" class="block rounded-md border-gray-300">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($selectedBranch?->id === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label><label>Currency<select name="currency_id" class="block rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected($selectedCurrency?->id === $currency->id)>{{ $currency->code }}</option>@endforeach</select></label><button class="rounded bg-gray-800 px-3 py-2 text-white">Show outstanding sources</button></form>
            @if($selectedBranch && $selectedCurrency)
                <p class="text-sm text-gray-600">Enter the amount paid against each exact original Receivable entry. Blank or zero rows are excluded before submission.</p>
                <form method="POST" enctype="multipart/form-data" action="{{ route('captains.financials.payouts.store', $captain) }}" class="space-y-4">@csrf
                    <input type="hidden" name="branch_id" value="{{ $selectedBranch->id }}"><input type="hidden" name="currency_id" value="{{ $selectedCurrency->id }}"><input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <div class="overflow-x-auto"><table class="min-w-full text-sm text-left"><thead><tr class="border-b"><th class="p-2">Source entry / Order</th><th class="p-2">Occurred</th><th class="p-2">Meaning</th><th class="p-2">Original</th><th class="p-2">Already paid</th><th class="p-2">Remaining</th><th class="p-2">This payout</th></tr></thead><tbody>
                        @forelse($outstanding as $entry)<tr class="border-b"><td class="p-2">#{{ $entry->financial_entry_id }} · {{ $entry->order_number ?: '—' }}</td><td class="p-2">{{ $entry->occurred_at }}</td><td class="p-2">{{ str_replace('_', ' ', $entry->event_type) }}</td><td class="p-2">{{ $entry->amount }}</td><td class="p-2">{{ $entry->paid_amount }}</td><td class="p-2">{{ $entry->remaining_amount }}</td><td class="p-2"><input type="hidden" name="allocations[{{ $entry->financial_entry_id }}][financial_entry_id]" value="{{ $entry->financial_entry_id }}"><input class="w-28 rounded-md border-gray-300" type="text" inputmode="decimal" name="allocations[{{ $entry->financial_entry_id }}][amount]" placeholder="0.00"></td></tr>
                        @empty<tr><td colspan="7" class="p-2">No final attributable Receivable sources available.</td></tr>@endforelse
                    </tbody></table></div>
                    <div class="grid gap-3 sm:grid-cols-2"><label>Total amount<input type="text" inputmode="decimal" name="amount" required class="block w-full rounded-md border-gray-300"></label><label>Occurred at<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="block w-full rounded-md border-gray-300"></label><label>Payment reference<input type="text" name="reference" class="block w-full rounded-md border-gray-300"></label><label>Evidence image<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full"></label><label class="sm:col-span-2">Note<textarea name="note" class="block w-full rounded-md border-gray-300"></textarea></label></div>
                    <x-primary-button>Record attributed payout</x-primary-button>
                </form>
            @endif
        </section>
        @endcan

        <div class="grid gap-5 lg:grid-cols-2">
            @can('captain_deposits.view')<section class="rounded-lg bg-white p-5 shadow"><h3 class="font-semibold">Deposit receipt history</h3>@forelse($receipts as $receipt)<p class="border-b py-2 text-sm">{{ $receipt->occurred_at->format('Y-m-d H:i') }} · {{ $receipt->branch->name }} · {{ $receipt->amount }} {{ $receipt->currency->code }} · {{ $receipt->receivedBy->name }} · {{ $receipt->reference ?: '—' }} @can('captain_financial_evidence.download')<a class="underline" href="{{ route('captains.financials.deposits.evidence', [$captain, $receipt]) }}">Evidence</a>@endcan</p>@empty<p class="text-sm">No receipts.</p>@endforelse</section>@endcan
            @can('captain_receivable_payouts.view')<section class="rounded-lg bg-white p-5 shadow"><h3 class="font-semibold">Receivable payout history</h3>@forelse($payouts as $payout)<div class="border-b py-2 text-sm"><p>{{ $payout->occurred_at->format('Y-m-d H:i') }} · {{ $payout->branch->name }} · {{ $payout->amount }} {{ $payout->currency->code }} · {{ $payout->paidBy->name }} · {{ $payout->reference ?: '—' }} @can('captain_financial_evidence.download')<a class="underline" href="{{ route('captains.financials.payouts.evidence', [$captain, $payout]) }}">Evidence</a>@endcan</p><ul class="list-disc pl-5 text-gray-600">@foreach($payout->allocations as $allocation)<li>Receivable entry #{{ $allocation->financial_entry_id }}: {{ $allocation->amount }}</li>@endforeach</ul></div>@empty<p class="text-sm">No payouts.</p>@endforelse</section>@endcan
        </div>
        <a class="underline text-sm" href="{{ route('captain-financials.index') }}">Captain financial history</a>
    </div>
</x-app-layout>
