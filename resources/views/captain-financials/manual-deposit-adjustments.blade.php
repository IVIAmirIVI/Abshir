<section class="rounded-lg bg-white p-5 shadow space-y-4">
    <h3 class="text-lg font-semibold">Manual Captain Deposit Adjustment</h3>
    <p class="text-sm text-gray-600">Exceptional administrative correction to this Captain's Deposit in the selected Branch and Currency. INCREASE adds funds to the ledger balance; DECREASE subtracts funds and may make it negative. This records an administrative adjustment, with its own reason and evidence. Use the dedicated workflows for physical Deposit receipts, payouts, refunds, recoveries and Order corrections.</p>
    <form method="POST" enctype="multipart/form-data" action="{{ route('captains.manual-deposit-adjustments.store', $captain) }}" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <label>Branch<select name="branch_id" required class="block w-full rounded-md border-gray-300">@foreach($adjustmentBranches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
        <label>Currency<select name="currency_id" required class="block w-full rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></label>
        <label>Direction<select name="direction" required class="block w-full rounded-md border-gray-300"><option value="">Choose direction</option><option value="INCREASE">INCREASE — add to Deposit</option><option value="DECREASE">DECREASE — subtract from Deposit (may become negative)</option></select></label>
        <label>Positive amount<input type="text" inputmode="decimal" name="amount" required class="block w-full rounded-md border-gray-300"></label>
        <label>Occurred at<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="block w-full rounded-md border-gray-300"></label>
        <label>Private evidence (JPEG/PNG/WebP, max 5 MB)<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full"></label>
        <label class="sm:col-span-2">Reason<textarea name="reason" required class="block w-full rounded-md border-gray-300"></textarea></label>
        <label class="sm:col-span-2"><input type="checkbox" required> I confirm the selected direction, amount, Branch and Currency for this immutable Deposit adjustment.</label>
        <div class="sm:col-span-2"><x-primary-button>Record Manual Deposit Adjustment</x-primary-button></div>
    </form>
    <h4 class="font-semibold">Manual Deposit adjustment history</h4>
    @forelse($manualAdjustments as $adjustment)
        <p class="border-b py-2 text-sm">{{ $adjustment->direction->value }} · {{ $adjustment->amount }} {{ $adjustment->currency->code }} · {{ $adjustment->branch->name }} · {{ $adjustment->adjustedBy->name }} · {{ $adjustment->occurred_at->format('Y-m-d H:i') }} · {{ $adjustment->reason }} <a class="underline" href="{{ route('captains.manual-deposit-adjustments.evidence', [$captain, $adjustment]) }}">Private evidence</a></p>
    @empty
        <p class="text-sm">No manual Deposit adjustments.</p>
    @endforelse
</section>
