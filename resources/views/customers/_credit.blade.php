@if(auth()->user()->can('customer_credits.view') && auth()->user()->can('customers.view'))
@php($creditCustomer = $creditCustomer ?? $customer ?? null)
@if($creditCustomer && auth()->user()->canAccessCustomer($creditCustomer))
<section class="min-w-0 space-y-3 rounded-xl border border-stone-200 bg-white p-4" data-customer-credit>
    <h2 class="font-semibold">{{ __('credit.title') }}</h2>
    <p class="text-sm text-gray-600">{{ __('credit.help') }}</p>
    @if(!\Illuminate\Support\Facades\Schema::hasTable('customer_credit_entries'))
        <p role="status">{{ __('credit.activation') }}</p>
    @else
        @php($creditService = app(\App\Services\CustomerCreditService::class))
        @php($creditBalances = $creditService->balances(auth()->user(), $creditCustomer, $creditBranchId ?? null))
        <div class="grid min-w-0 gap-3 sm:grid-cols-2">
        @forelse($creditBalances as $balance)
            <p class="rounded-lg border p-3">{{ $balance->branch_name }} · <x-ltr>{{ $balance->currency_code }}</x-ltr> · {{ __('credit.available') }}: <x-decimal :value="$balance->available" /></p>
        @empty<p>{{ __('credit.empty') }}</p>@endforelse
        </div>
        @can('customer_credits.redeem')<p class="text-sm text-gray-600">{{ \Illuminate\Support\Facades\Schema::hasTable('customer_credit_settlements') ? __('credit.workflow_help') : __('credit.redemption_blocked') }}</p>@endcan
        @can('customer_credits.payout')
        @if(\Illuminate\Support\Facades\Schema::hasTable('customer_credit_cash_payouts'))
            @if($creditShowGrant ?? false)
            @foreach($creditBalances as $balance)
            @if(\Brick\Math\BigDecimal::of($balance->available)->isGreaterThan(0))
            <details class="rounded-lg border p-3"><summary class="cursor-pointer font-semibold">{{ __('credit.cash_payout') }} · {{ $balance->branch_name }} · {{ $balance->currency_code }}</summary>
                <p class="mt-3 text-sm">{{ __('credit.cash_payout_help') }}</p>
                <form method="POST" enctype="multipart/form-data" action="{{ route('customers.credits.payout', $creditCustomer) }}" class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2">@csrf
                    <input type="hidden" name="request_key" value="{{ (string) str()->uuid() }}"><input type="hidden" name="branch_id" value="{{ $balance->branch_id }}"><input type="hidden" name="currency_id" value="{{ $balance->currency_id }}">
                    <input type="hidden" name="amount" value="{{ $balance->available }}">
                    <p>{{ __('credit.full_available_payout') }}: <x-decimal :value="$balance->available" /> {{ $balance->currency_code }}</p>
                    <label>{{ __('common.terms.captain') }}<select required name="captain_id" class="mt-1 w-full"><option value="">{{ __('common.terms.captain') }}</option>
                        @foreach(\App\Models\Captain::accessibleTo(auth()->user())->whereHas('user', fn ($q) => $q->where('is_active', true))->where(fn ($q) => $q->where('home_branch_id', $balance->branch_id)->orWhereHas('branchAssignments', fn ($a) => $a->where('branch_id', $balance->branch_id)->where('starts_at', '<=', now())->where(fn ($dates) => $dates->whereNull('ends_at')->orWhere('ends_at', '>', now()))))->with('user')->orderBy('id')->get() as $payoutCaptain)<option value="{{ $payoutCaptain->id }}">{{ $payoutCaptain->user->name }}</option>@endforeach
                    </select></label>
                    <label>{{ __('credit.reference') }}<input required name="reference" maxlength="191" class="mt-1 w-full"></label>
                    <label>{{ __('credit.time') }}<x-text-input required name="occurred_at" type="text" data-management-datetime dir="ltr" :value="now()->format('Y-m-d\TH:i:s')" class="mt-1 w-full" /></label>
                    <label class="sm:col-span-2">{{ __('credit.reason') }}<textarea required name="reason" maxlength="2000" class="mt-1 w-full"></textarea></label>
                    <label>{{ __('returns.evidence') }}<input required name="evidence" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 w-full"></label>
                    <label class="flex items-start gap-2 text-sm sm:col-span-2"><input required type="checkbox" name="personal_cash_confirmed" value="1"><span>{{ __('credit.personal_cash_attestation') }}</span></label>
                    <x-orders.action>{{ __('credit.record_cash_payout') }}</x-orders.action>
                </form>
            </details>
            @endif
            @endforeach
            @endif
        @else<p class="text-sm text-amber-800">{{ __('credit.payout_blocked') }}</p>@endif
        @endcan
        @if($creditShowGrant ?? false) @can('customer_credits.grant')
        <details class="rounded-lg border p-3"><summary class="cursor-pointer font-semibold">{{ __('credit.grant') }}</summary>
            <form method="POST" action="{{ route('customers.credits.store', $creditCustomer) }}" class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2">@csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) str()->uuid()) }}">
                <label>{{ __('common.terms.branch') }}<select required name="branch_id" class="mt-1 w-full">@foreach(auth()->user()->availableBranches()->get() as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                <label>{{ __('common.terms.currency') }}<select required name="currency_id" class="mt-1 w-full">@foreach(\App\Models\Currency::where('is_active', true)->orderBy('code')->get() as $currency)<option value="{{ $currency->id }}" @selected(old('currency_id') == $currency->id)>{{ $currency->code }}</option>@endforeach</select></label>
                <label>{{ __('credit.reason') }}<select required name="reason" class="mt-1 w-full">@foreach(['OVERCHARGE','ERROR'] as $reason)<option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ __('credit.'.$reason) }}</option>@endforeach</select></label>
                <label>{{ __('credit.amount') }}<input required type="text" inputmode="decimal" dir="ltr" name="amount" value="{{ old('amount') }}" class="mt-1 w-full"></label>
                <label>{{ __('credit.reference') }}<input required name="reference" maxlength="191" value="{{ old('reference') }}" class="mt-1 w-full"></label>
                <label>{{ __('credit.order') }}<input type="text" inputmode="numeric" name="source_order_id" value="{{ old('source_order_id') }}" class="mt-1 w-full"></label>
                <label>{{ __('credit.time') }}<x-text-input required type="text" name="occurred_at" data-management-datetime dir="ltr" :value="old('occurred_at', now()->format('Y-m-d\TH:i:s'))" class="mt-1 w-full" /></label>
                <label class="sm:col-span-2">{{ __('credit.notes') }}<textarea required name="notes" maxlength="5000" class="mt-1 w-full">{{ old('notes') }}</textarea></label>
                <x-orders.action>{{ __('credit.grant') }}</x-orders.action>
            </form>
        </details>
        @endcan @endif
        <details><summary class="cursor-pointer font-semibold">{{ __('credit.history') }}</summary>
            @foreach($creditService->history(auth()->user(), $creditCustomer, $creditBranchId ?? null) as $entry)
                <p class="mt-2 break-words text-sm">{{ $entry->branch_name }} · <x-decimal :value="$entry->amount" /> <x-ltr>{{ $entry->currency_code }}</x-ltr> · {{ __('credit.'.$entry->reason) }} · {{ $entry->notes }} · {{ $entry->reference }} · {{ \App\Support\UiDate::datetime($entry->occurred_at) }} · #{{ $entry->recorded_by }}
                    @if($entry->source_order_id && auth()->user()->can('orders.view')) · <a class="underline" href="{{ route('orders.workflow', $entry->source_order_id) }}">{{ __('orders.workflow.title') }} #{{ $entry->source_order_id }}</a>@endif
                    @if(isset($entry->cash_payout_id)) · <a class="underline" href="{{ route('customers.credits.payout-evidence', [$creditCustomer, $entry->cash_payout_id]) }}">{{ __('returns.evidence') }}</a>@endif
                </p>
            @endforeach
        </details>
    @endif
</section>
@endif
@endif
