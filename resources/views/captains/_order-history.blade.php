<section id="captain-order-history" class="space-y-4" aria-labelledby="captain-history-title" data-captain-order-history>
    <h2 id="captain-history-title" class="text-lg font-bold">{{ __('captain_dashboard.order_history') }}</h2>
    <p class="text-sm text-gray-600">{{ __('captain_dashboard.order_history_help') }}</p>
    <form method="GET" action="{{ $snapshot['selfView'] ? route('captain.self-information') : route('captains.information', $captain) }}#captain-order-history" class="grid gap-3 rounded-xl border border-stone-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-3">
        <input type="hidden" name="period" value="{{ $snapshot['period'] }}">
        <input type="hidden" name="branch_id" value="{{ $snapshot['branchId'] }}">
        @foreach(['from', 'to'] as $bound)
            <label class="block text-sm"><span>{{ __('captain_dashboard.orders_'.$bound) }}</span><input type="text" name="orders_{{ $bound }}" value="{{ $orderHistory['filters']['orders_'.$bound] ?? '' }}" dir="ltr" inputmode="numeric" placeholder="YYYY-MM-DD" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" class="mt-1 w-full rounded-lg border-gray-300"><x-input-error :messages="$errors->get('orders_'.$bound)" /></label>
        @endforeach
        <label class="block text-sm"><span>{{ __('orders.table.status') }}</span><select name="orders_status" class="mt-1 w-full"><option value="">{{ __('captain_dashboard.orders_all') }}</option>@foreach(\App\Enums\OrderOperationalStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($orderHistory['filters']['orders_status'] ?? '') === $status->value)>{{ __('orders.statuses.'.$status->value) }}</option>@endforeach</select></label>
        <label class="block text-sm"><span>{{ __('captain_dashboard.orders_kind') }}</span><select name="orders_kind" class="mt-1 w-full"><option value="">{{ __('captain_dashboard.orders_all') }}</option>@foreach(['single', 'shared'] as $kind)<option value="{{ $kind }}" @selected(($orderHistory['filters']['orders_kind'] ?? '') === $kind)>{{ __('captain_dashboard.orders_'.$kind) }}</option>@endforeach</select></label>
        <label class="block text-sm"><span>{{ __('captain_dashboard.orders_role') }}</span><select name="orders_role" class="mt-1 w-full"><option value="">{{ __('captain_dashboard.orders_all') }}</option>@foreach(['collector', 'support', 'former'] as $role)<option value="{{ $role }}" @selected(($orderHistory['filters']['orders_role'] ?? '') === $role)>{{ __('captain_dashboard.orders_'.$role) }}</option>@endforeach</select></label>
        <div class="flex flex-wrap items-end gap-2"><x-orders.action>{{ __('dashboard.refresh') }}</x-orders.action><a class="btn btn-ghost" href="{{ ($snapshot['selfView'] ? route('captain.self-information') : route('captains.information', $captain)).'?'.http_build_query(['period'=>$snapshot['period'], 'branch_id'=>$snapshot['branchId']]) }}#captain-order-history">{{ __('captain_dashboard.orders_clear') }}</a></div>
    </form>
    @if($orderHistory['financial'])<p class="text-xs text-gray-600">{{ __('captain_dashboard.orders_fee_help') }}</p>@endif
    <div class="grid gap-3 md:grid-cols-2">
        @forelse($orderHistory['orders'] as $historicalOrder)
            <article class="min-w-0 space-y-2 rounded-xl border border-stone-200 bg-white p-4" data-captain-order="{{ $historicalOrder->id }}">
                <div class="flex flex-wrap justify-between gap-2">
                    @if($historicalOrder->history_can_open)<a class="font-semibold underline" href="{{ route('orders.show', $historicalOrder) }}"><x-ltr>{{ $historicalOrder->order_number }}</x-ltr></a>@else<span class="font-semibold"><x-ltr>{{ $historicalOrder->order_number }}</x-ltr></span>@endif
                    <x-orders.indicator>{{ __('orders.statuses.'.$historicalOrder->operational_status->value) }}</x-orders.indicator>
                </div>
                <p class="text-sm"><x-ltr>{{ \App\Support\UiDate::datetime($historicalOrder->ordered_at) }}</x-ltr> · {{ __('captain_dashboard.orders_'.($historicalOrder->history_shared ? 'shared' : 'single')) }}</p>
                @if($historicalOrder->history_customer_name !== null)<p class="break-words text-sm">{{ $historicalOrder->history_customer_name }}</p>@endif
                <p class="text-sm">{{ __('captain_dashboard.orders_role') }}: @foreach($historicalOrder->captainAssignments->pluck('assignment_role')->unique() as $role){{ __('captain_dashboard.orders_'.($role === \App\Enums\OrderCaptainRole::PrimaryCollection ? 'collector' : 'support')) }}@if(!$loop->last) / @endif @endforeach @if($historicalOrder->captainAssignments->every(fn($a) => $a->removed_at !== null)) · {{ __('captain_dashboard.orders_former') }}@endif</p>
                <p class="text-sm">{{ __('shared_orders.payment_method') }}: {{ ($method = $orderHistory['methods']->get($historicalOrder->id)) ? __('financial.collection_methods.'.$method) : __('captain_dashboard.orders_uncollected') }}</p>
                @if($orderHistory['financial'])<p class="text-sm">{{ __('captain_dashboard.orders_fee') }}: @if($fee = $orderHistory['fees']->get($historicalOrder->id))<x-money :amount="$fee->amount" :currency="$fee->currency_code" />@else{{ __('common.operational_unavailable') }}@endif</p>@endif
            </article>
        @empty<p class="text-sm text-gray-600">{{ __('captain_dashboard.orders_empty') }}</p>@endforelse
    </div>
    {{ $orderHistory['orders']->fragment('captain-order-history')->links() }}
</section>
