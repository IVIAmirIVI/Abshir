<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('commissions.account') }} — {{ $shop->name }}</h2></x-slot>
    <x-management.page>
        <p class="rounded-xl border p-4 text-sm">{{ __('commissions.subledger_notice') }}</p>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(!$installed)<p>{{ __('commissions.activation_required') }}</p>@else
            <div class="grid gap-4 md:grid-cols-2">
                @forelse($accounts as $account)
                    <x-management.record>
                        <h3 class="font-bold">{{ $account->branch_name }} · <x-ltr>{{ $account->code }}</x-ltr></h3>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            @foreach(['earned', 'reversed', 'paid', 'outstanding', 'credit'] as $key)
                                <div><dt>{{ __('commissions.'.$key) }}</dt><dd><x-money :amount="$account->totals[$key]" /> <x-ltr>{{ $account->code }}</x-ltr></dd></div>
                            @endforeach
                        </dl>
                        @can('shop_partnerships.update')
                            @if(\Brick\Math\BigDecimal::of($account->totals['outstanding'])->isGreaterThan(0))
                                <form method="POST" action="{{ route('shops.commission-payments.store', [$shop, $account->id]) }}" class="grid gap-3 sm:grid-cols-2">
                                    @csrf <input type="hidden" name="request_key" value="{{ old('request_key', (string) str()->uuid()) }}">
                                    <label>{{ __('commissions.payment_amount') }}<x-text-input name="amount" required inputmode="decimal" dir="ltr" class="w-full" value="{{ old('amount') }}" /></label>
                                    <label>{{ __('commissions.payment_method') }}<select name="payment_method" required class="w-full">@foreach(['CASH', 'TRANSFER'] as $method)<option value="{{ $method }}">{{ __('commissions.payment_methods.'.$method) }}</option>@endforeach</select></label>
                                    <label>{{ __('commissions.payment_time') }}<x-text-input type="datetime-local" name="occurred_at" required dir="ltr" value="{{ old('occurred_at', now()->setTimezone('Asia/Damascus')->format('Y-m-d\TH:i')) }}" /></label>
                                    <label>{{ __('commissions.reference') }}<x-text-input name="reference" maxlength="255" dir="auto" value="{{ old('reference') }}" /></label>
                                    <p class="text-xs sm:col-span-2">{{ __('commissions.payment_confirmation') }}</p>
                                    <x-orders.action>{{ __('commissions.record_payment') }}</x-orders.action>
                                </form>
                            @endif
                        @endcan
                    </x-management.record>
                @empty<p>{{ __('commissions.account_empty') }}</p>@endforelse
            </div>
            <h3 class="font-bold">{{ __('commissions.transactions') }}</h3>
            <div class="grid gap-3 md:grid-cols-2">
                @forelse($history as $event)
                    <x-management.record>
                        <p>{{ __('commissions.kinds.'.$event->kind) }} · <x-money :amount="$event->amount" /> <x-ltr>{{ $event->code }}</x-ltr></p>
                        <p class="text-xs"><x-ltr>{{ \App\Support\UiDate::datetime($event->occurred_at, true) }}</x-ltr> · {{ $event->actor_name }}</p>
                        @if($event->payment_method)<p>{{ __('commissions.payment_methods.'.$event->payment_method) }} · {{ $event->reference ?: '—' }}</p>@endif
                        @if($event->reason)<p>{{ $event->reason }}</p>@endif
                        @if($event->order_id)
                            @can('orders.view')<a class="underline text-sm" href="{{ route('orders.show', $event->order_id) }}">{{ __('commissions.source_order') }} <x-ltr>#{{ $event->order_id }}</x-ltr></a>@endcan
                            @can('invoices.view')<a class="underline text-sm" href="{{ route('orders.invoices.show', [$event->order_id, $event->invoice_id]) }}"><x-ltr>{{ $event->invoice_reference }}</x-ltr></a>@endcan
                        @endif
                    </x-management.record>
                @empty<p>{{ __('commissions.account_empty') }}</p>@endforelse
            </div>
            {{ $history->links() }}
        @endif
        <a class="btn btn-ghost" href="{{ route('shops.show', $shop) }}">{{ __('shops.ui_back_to_shops') }}</a>
    </x-management.page>
</x-app-layout>
