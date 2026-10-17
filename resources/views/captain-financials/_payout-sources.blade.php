<div class="space-y-3" data-payout-sources>
    @forelse($outstanding as $entry)
        <article class="min-w-0 rounded-xl border border-stone-200 bg-stone-50 p-4" data-payout-source="{{ $entry->financial_entry_id }}">
            <h4 class="break-words font-semibold"><x-financial.receivable-label :event-type="$entry->event_type" /></h4>
            <dl id="payout-source-{{ $entry->financial_entry_id }}-balances" class="mt-2 text-sm">
                <dt class="text-gray-600">{{ __('financial.ui.remaining') }}</dt>
                <dd class="captain-balance-value text-xl font-bold text-brand-primary"><x-money :amount="$entry->remaining_amount" :currency="$selectedCurrency->code" /></dd>
            </dl>
            <div class="mt-3 grid min-w-0 gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <label class="block min-w-0" for="payout-source-{{ $entry->financial_entry_id }}">{{ __('financial.ui.this_payout') }}
                    <input type="hidden" name="allocations[{{ $entry->financial_entry_id }}][financial_entry_id]" value="{{ $entry->financial_entry_id }}">
                    <input id="payout-source-{{ $entry->financial_entry_id }}" data-payout-allocation class="mt-1 block w-full rounded-lg" type="text" inputmode="decimal" name="allocations[{{ $entry->financial_entry_id }}][amount]" value="{{ old('allocations.'.$entry->financial_entry_id.'.amount') }}" placeholder="0" dir="ltr" aria-describedby="payout-source-{{ $entry->financial_entry_id }}-balances" aria-invalid="{{ $errors->has('allocations.'.$entry->financial_entry_id.'.amount') ? 'true' : 'false' }}">
                    <x-input-error :messages="$errors->get('allocations.'.$entry->financial_entry_id.'.amount')" />
                </label>
                <x-secondary-button data-payout-fill="payout-source-{{ $entry->financial_entry_id }}" data-remaining="{{ $entry->remaining_amount }}">{{ __('financial.ui.pay_remaining') }}</x-secondary-button>
            </div>
            <details class="mt-3 text-sm text-gray-600">
                <summary class="cursor-pointer">{{ __('financial.ui.attribution_details') }}</summary>
                <div class="mt-2 space-y-1">
                    <p>{{ __('financial.ui.receivable_entry') }} <x-ltr>#{{ $entry->financial_entry_id }}</x-ltr> · <x-ltr>{{ $entry->order_number ?: '—' }}</x-ltr></p>
                    <p>{{ \App\Support\UiLabels::label('financial.events', $entry->event_type) }} · <x-ltr>{{ \App\Support\UiDate::datetime($entry->occurred_at) }}</x-ltr></p>
                    <p>{{ __('financial.ui.original') }}: <x-money :amount="$entry->amount" :currency="$selectedCurrency->code" /> · {{ __('financial.ui.already_paid') }}: <x-money :amount="$entry->paid_amount" :currency="$selectedCurrency->code" /></p>
                </div>
            </details>
        </article>
    @empty<p role="status" class="rounded-lg bg-stone-50 p-4 text-sm">{{ __('financial.ui.no_sources') }}</p>@endforelse
</div>
<x-input-error :messages="$errors->get('allocations')" />
