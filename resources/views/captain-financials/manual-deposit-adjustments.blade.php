<section class="rounded-lg bg-white p-5 shadow space-y-4">
    <h3 class="text-lg font-semibold">{{ __('financial.terms.manual_deposit_adjustment') }}</h3>
    <p class="text-sm text-gray-600">{{ __('financial.ui.manual_help') }}</p>
    <form method="POST" enctype="multipart/form-data" action="{{ route('captains.manual-deposit-adjustments.store', $captain) }}" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <label>{{ __('common.terms.branch') }}<select name="branch_id" required class="block w-full rounded-md border-gray-300">@foreach($adjustmentBranches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
        <label>{{ __('common.terms.currency') }}<select name="currency_id" required class="block w-full rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ "\u{2066}".($currency->code)."\u{2069}" }}</option>@endforeach</select></label>
        <label>{{ __('financial.ui.direction') }}<select name="direction" required class="block w-full rounded-md border-gray-300"><option value="">{{ __('financial.ui.choose_direction') }}</option><option value="INCREASE">{{ __('financial.ui.increase_deposit') }}</option><option value="DECREASE">{{ __('financial.ui.decrease_deposit') }}</option></select></label>
        <label>{{ __('financial.ui.positive_amount') }}<input type="text" inputmode="decimal" name="amount" required class="block w-full rounded-md border-gray-300" dir="ltr"></label>
        <label>{{ __('financial.ui.occurred_at') }}<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="block w-full rounded-md border-gray-300" dir="ltr"></label>
        <label>{{ __('financial.ui.private_evidence_help') }}<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full" dir="auto"></label>
        <label class="sm:col-span-2">{{ __('financial.ui.adjustment_reason') }}<textarea name="reason" required class="block w-full rounded-md border-gray-300"></textarea></label>
        <label class="sm:col-span-2"><input type="checkbox" required> {{ __('financial.ui.adjustment_confirm') }}</label>
        <div class="sm:col-span-2"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.record_adjustment') }}</x-primary-button></div>
    </form>
    <h4 class="font-semibold">{{ __('financial.ui.adjustment_history') }}</h4>
    @forelse($manualAdjustments as $adjustment)
        <p class="border-b py-2 text-sm">{{ \App\Support\UiLabels::label('financial.directions', $adjustment->direction) }} · <x-money :amount="$adjustment->amount" /> <x-ltr>{{ $adjustment->currency->code }}</x-ltr> · {{ $adjustment->branch->name }} · {{ __('admin.roles.SUPER_ADMIN') }}: {{ $adjustment->adjustedBy->name }} · <x-ltr>{{ $adjustment->occurred_at->format('Y-m-d H:i') }}</x-ltr> · {{ $adjustment->reason }} <a class="underline" href="{{ route('captains.manual-deposit-adjustments.evidence', [$captain, $adjustment]) }}">{{ __('financial.ui.private_evidence') }}</a></p>
    @empty
        <p class="text-sm">{{ __('financial.ui.no_adjustments') }}</p>
    @endforelse
</section>
