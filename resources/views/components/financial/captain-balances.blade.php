@props(['rows', 'global' => false, 'inlineCurrency' => false, 'emptyLabel' => 'financial.ui.no_branch_history'])
<div class="min-w-0 space-y-3" data-captain-balances>
    @forelse($rows as $row)
        <x-management.record>
            <h3 class="break-words font-semibold" @if($global) data-global-currency="{{ $row['currency']->code }}" @endif>
                @if($global)<x-ltr>{{ $row['currency']->code }}</x-ltr> · {{ $row['currency']->name }}
                @else{{ $row['branch']->name }} · <x-ltr>{{ $row['currency']->code }}</x-ltr>@endif
            </h3>
            @unless($global)<p class="mt-1 text-xs text-gray-600">{{ __('captain_dashboard.branch_subtotal') }}</p>@endunless
            <dl class="captain-balance-grid mt-3">
                @foreach(['CAPTAIN_DEPOSIT', 'CAPTAIN_COMPANY_RECEIVABLE', 'CAPTAIN_CASH_CUSTODY'] as $kind)
                    <div class="captain-balance-card" data-captain-balance="{{ $kind }}">
                        <dt class="text-sm leading-6 text-gray-600">{{ __('financial.accounts.'.$kind) }}</dt>
                        <dd class="captain-balance-value mt-2 text-xl font-bold text-brand-primary"><x-money :amount="$row['values'][$kind]" :currency="$inlineCurrency ? $row['currency']->code : null" /></dd>
                    </div>
                @endforeach
            </dl>
        </x-management.record>
    @empty<p class="text-sm text-gray-600">{{ __($emptyLabel) }}</p>@endforelse
</div>
