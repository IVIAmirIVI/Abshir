<x-app-layout>
    <x-slot name="header"><h2 class="min-w-0 break-words text-xl font-semibold">{{ __('financial.ui.branch_financials') }}</h2></x-slot>
    <x-management.page class="financial-ui captain-financial-page">
        <x-captains.navigation :captain="$captain" />
        <header class="min-w-0 space-y-1"><h1 class="break-words text-xl font-bold">{{ $captain->user->name }}</h1>@if($selectedBranch && $selectedCurrency)<p class="text-sm text-gray-600">{{ $selectedBranch->name }} · <x-ltr>{{ $selectedCurrency->code }}</x-ltr></p>@endif</header>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="rounded-lg bg-white p-5 shadow space-y-3">
            <h3 class="text-lg font-semibold">{{ __('financial.ui.independent_balances') }}</h3>
            <p class="text-sm text-gray-600">{{ __('financial.ui.exact_balances_help') }}</p>
            <x-financial.captain-balances :rows="$rows" />
            <x-financial.account-guide />
        </section>

        @include('captains._return-liabilities')
        @can('captain_receivable_payouts.create')
        <details id="captain-payout" name="captain-financial-tasks" class="captain-financial-task" @if(!old('direction') && (old('allocations') !== null || !old('request_key'))) open @endif><summary>{{ __('financial.ui.pay_attributed') }}</summary><div class="captain-financial-task-body">
            <form method="GET" action="{{ route('captains.financials.show', $captain) }}" class="grid min-w-0 gap-3 sm:grid-cols-2"><label>{{ __('common.terms.branch') }}<select name="branch_id" class="min-w-0 max-w-full block w-full rounded-md border-gray-300">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($selectedBranch?->id === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label><label>{{ __('common.terms.currency') }}<select name="currency_id" class="min-w-0 max-w-full block w-full rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected($selectedCurrency?->id === $currency->id)>{{ "\u{2066}".($currency->code)."\u{2069}" }}</option>@endforeach</select></label><x-secondary-button type="submit">{{ __('financial.ui.show_sources') }}</x-secondary-button></form>
            @if($selectedBranch && $selectedCurrency)
                @if($outstanding->isNotEmpty())
                <p id="payout-allocation-help" class="text-sm leading-6 text-gray-600">{{ __('financial.ui.allocation_help') }}</p>
                <form method="POST" enctype="multipart/form-data" action="{{ route('captains.financials.payouts.store', $captain) }}" class="space-y-4" data-captain-payout data-invalid-amount="{{ __('captain_requests.invalid_amount') }}">@csrf
                    <input type="hidden" name="branch_id" value="{{ $selectedBranch->id }}"><input type="hidden" name="currency_id" value="{{ $selectedCurrency->id }}"><input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    @include('captain-financials._payout-sources')
                    <div class="grid gap-3 sm:grid-cols-2"><label>{{ __('financial.ui.total_amount') }}<input type="text" inputmode="decimal" name="amount" value="0" data-payout-total readonly required class="block w-full rounded-md border-gray-300" dir="ltr" aria-describedby="payout-allocation-help"></label><label>{{ __('financial.ui.occurred_at') }}<input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" required class="block w-full rounded-md border-gray-300" dir="ltr"></label><label>{{ __('financial.ui.payment_reference') }}<input type="text" name="reference" value="{{ old('reference') }}" class="block w-full rounded-md border-gray-300" dir="ltr"></label><label>{{ __('financial.ui.evidence_image') }}<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full" dir="auto"></label><label class="sm:col-span-2">{{ __('financial.ui.note') }}<textarea name="note" class="block w-full rounded-md border-gray-300">{{ old('note') }}</textarea></label></div>
                    @if($benefitPayoutMetadataAvailable)
                        <label class="block">{{ __('captain_requests.payment_method') }}<select name="payment_method" required class="block w-full rounded-lg"><option value="CASH" @selected(old('payment_method', 'CASH') === 'CASH')>{{ __('captain_requests.methods.CASH') }}</option><option value="TRANSFER" @selected(old('payment_method') === 'TRANSFER')>{{ __('captain_requests.methods.TRANSFER') }}</option></select></label>
                        <input type="hidden" name="received_by_user_id" value="{{ $captain->user_id }}">
                        <p>{{ __('captain_requests.received_by') }}: {{ $captain->user->name }}</p>
                    @endif
                    <p class="text-sm leading-6 text-gray-600">{{ __('financial.ui.allocation_total_help') }}</p>
                    <x-primary-button class="w-full justify-center sm:w-auto" :disabled="$outstanding->isEmpty()">{{ __('financial.ui.record_payout') }}</x-primary-button>
                </form>
                @else
                    <p role="status" class="rounded-lg bg-stone-50 p-4">{{ __('financial.ui.no_payable_entitlements') }}</p>
                    @can('captain_receivable_payouts.view')<a class="link" href="#captain-payout-history">{{ __('financial.ui.previous_payouts') }}</a>@endcan
                @endif
            @endif
        </div></details>
        @endcan
        @can('captain_deposits.create')
        <details id="captain-deposit" name="captain-financial-tasks" class="captain-financial-task" @if(!auth()->user()->can('captain_receivable_payouts.create') || (old('request_key') && old('allocations') === null && !old('direction'))) open @endif><summary>{{ __('financial.terms.deposit_receipt') }}</summary><div class="captain-financial-task-body">
            <form method="POST" enctype="multipart/form-data" action="{{ route('captains.financials.deposits.store', $captain) }}" class="grid gap-3 sm:grid-cols-2">@csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <label>{{ __('common.terms.branch') }}<select name="branch_id" required class="block w-full rounded-md border-gray-300">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <label>{{ __('common.terms.currency') }}<select name="currency_id" required class="block w-full rounded-md border-gray-300">@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ "\u{2066}".($currency->code)."\u{2069}" }}</option>@endforeach</select></label>
                <label>{{ __('financial.ui.amount') }}<input type="text" inputmode="decimal" name="amount" required class="block w-full rounded-md border-gray-300" dir="ltr"></label>
                <label>{{ __('financial.ui.occurred_at') }}<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="block w-full rounded-md border-gray-300" dir="ltr"></label>
                <label>{{ __('financial.ui.reference') }}<input type="text" name="reference" class="block w-full rounded-md border-gray-300" dir="ltr"></label>
                <label>{{ __('financial.ui.evidence_image') }}<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required class="block w-full" dir="auto"></label>
                <label class="sm:col-span-2">{{ __('financial.ui.note') }}<textarea name="note" class="block w-full rounded-md border-gray-300"></textarea></label>
                <div class="sm:col-span-2"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('financial.ui.record_deposit') }}</x-primary-button></div>
            </form>
        </div></details>
        @endcan


        @include('captain-financials._history')
        @if($manualAdjustmentsEnabled)
<details id="captain-adjustment" name="captain-financial-tasks" class="captain-financial-task captain-financial-task--admin" @if(old('direction')) open @endif><summary>{{ __('financial.terms.manual_deposit_adjustment') }} · {{ __('admin.roles.SUPER_ADMIN') }}</summary><div class="captain-financial-task-body">
            @include('captain-financials.manual-deposit-adjustments')
            </div></details>
        @endif
        <a class="underline text-sm" href="{{ route('captain-financials.index') }}">{{ __('financial.ui.captain_history') }}</a>
    </x-management.page>
</x-app-layout>
