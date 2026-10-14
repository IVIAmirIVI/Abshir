<div class="space-y-3" data-payout-sources>
    @forelse($outstanding as $entry)
        <article class="min-w-0 rounded-xl border border-stone-200 bg-stone-50 p-4" data-payout-source="{{ $entry->financial_entry_id }}">
            <h4 class="break-words font-semibold">{{ __('financial.ui.receivable_entry') }} <x-ltr>#{{ $entry->financial_entry_id }}</x-ltr> · {{ \App\Support\UiLabels::label('financial.events', $entry->event_type) }}</h4>
            <p class="mt-1 text-sm text-gray-600"><x-ltr>{{ $entry->order_number ?: '—' }} · {{ \App\Support\UiDate::datetime($entry->occurred_at) }}</x-ltr></p>
            <dl id="payout-source-{{ $entry->financial_entry_id }}-balances" class="mt-3 grid min-w-0 grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                @foreach(['original' => 'amount', 'already_paid' => 'paid_amount', 'remaining' => 'remaining_amount'] as $label => $field)
                    <div class="min-w-0"><dt class="text-gray-600">{{ __('financial.ui.'.$label) }}</dt><dd class="captain-balance-value font-semibold"><x-money :amount="$entry->{$field}" :currency="$selectedCurrency->code" /></dd></div>
                @endforeach
            </dl>
            <div class="mt-3 grid min-w-0 gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <label class="block min-w-0" for="payout-source-{{ $entry->financial_entry_id }}">{{ __('financial.ui.this_payout') }}
                    <input type="hidden" name="allocations[{{ $entry->financial_entry_id }}][financial_entry_id]" value="{{ $entry->financial_entry_id }}">
                    <input id="payout-source-{{ $entry->financial_entry_id }}" data-payout-allocation class="mt-1 block w-full rounded-lg" type="text" inputmode="decimal" name="allocations[{{ $entry->financial_entry_id }}][amount]" value="{{ old('allocations.'.$entry->financial_entry_id.'.amount') }}" placeholder="0" dir="ltr" aria-describedby="payout-source-{{ $entry->financial_entry_id }}-balances" aria-invalid="{{ $errors->has('allocations.'.$entry->financial_entry_id.'.amount') ? 'true' : 'false' }}">
                    <x-input-error :messages="$errors->get('allocations.'.$entry->financial_entry_id.'.amount')" />
                </label>
                <x-secondary-button data-payout-fill="payout-source-{{ $entry->financial_entry_id }}" data-remaining="{{ $entry->remaining_amount }}">{{ __('financial.ui.pay_remaining') }}</x-secondary-button>
            </div>
        </article>
    @empty<p role="status" class="rounded-lg bg-stone-50 p-4 text-sm">{{ __('financial.ui.no_sources') }}</p>@endforelse
</div>
<x-input-error :messages="$errors->get('allocations')" />
