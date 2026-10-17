<dl class="grid min-w-0 gap-3 rounded-lg bg-stone-50 p-3 text-sm lg:grid-cols-3" data-financial-account-guide>
    @foreach(['CAPTAIN_DEPOSIT' => 'deposit_definition', 'CAPTAIN_COMPANY_RECEIVABLE' => 'receivable_definition', 'CAPTAIN_CASH_CUSTODY' => 'custody_definition'] as $account => $definition)
        <div class="min-w-0"><dt class="font-semibold">{{ __('financial.accounts.'.$account) }}</dt><dd class="mt-1 leading-6 text-gray-600">{{ __('financial.ui.'.$definition) }}</dd></div>
    @endforeach
</dl>
