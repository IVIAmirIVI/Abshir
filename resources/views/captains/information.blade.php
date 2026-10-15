<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold break-words">{{ __('captain_dashboard.title') }}</h2></x-slot>
    <x-management.page class="financial-ui captain-information-page">
        <div data-captain-information class="min-w-0 space-y-4">
            @unless($snapshot['selfView'])<x-captains.navigation :captain="$captain" />@endunless
            @if($snapshot['selfView'])<a class="btn btn-primary" href="{{ route('captain.portal') }}">{{ __('captain_portal.title') }}</a>@endif
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0"><h1 class="text-2xl font-bold break-words">{{ $captain->user->name }}</h1><p class="mt-2 text-sm text-gray-600">{{ __($snapshot['selfView'] ? 'captain_dashboard.self_read_only' : 'captain_dashboard.read_only') }}</p></div>

            </header>
            <form method="GET" action="{{ $snapshot['selfView'] ? route('captain.self-information') : route('captains.information', $captain) }}" class="grid min-w-0 gap-3 rounded-xl border border-stone-200 bg-white p-4 sm:grid-cols-2">
                <label class="block min-w-0"><span class="mb-1 block text-sm font-semibold">{{ __('dashboard.period') }}</span><select class="w-full" name="period">@foreach(['today', '7days', '30days'] as $option)<option value="{{ $option }}" @selected($snapshot['period'] === $option)>{{ __('dashboard.'.$option) }}</option>@endforeach</select></label>
                <label class="block min-w-0"><span class="mb-1 block text-sm font-semibold">{{ __('common.terms.branch') }}</span><select class="w-full" name="branch_id"><option value="">{{ __('dashboard.all_branches') }}</option>@foreach($snapshot['branches'] as $branch)<option value="{{ $branch->id }}" @selected($snapshot['branchId'] === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                <button type="submit" class="btn btn-primary justify-self-start">{{ __('dashboard.refresh') }}</button>
                <p class="text-xs leading-5 text-gray-600 sm:col-span-2">{{ __('dashboard.timezone') }}</p>
                @if($snapshot['globalBalances'])<p class="text-xs leading-5 text-gray-600 sm:col-span-2">{{ __('captain_dashboard.filters_help') }}</p>@endif
            </form>

            @if($snapshot['financial'])
                <section class="space-y-3" aria-labelledby="captain-current-balances">
                    <h2 id="captain-current-balances" class="text-lg font-bold">{{ __($snapshot['globalBalances'] ? 'captain_dashboard.balances' : 'captain_dashboard.branch_activity') }}</h2>
                    <p class="text-sm leading-6 text-gray-600">{{ __($snapshot['globalBalances'] ? 'captain_dashboard.balances_help' : 'captain_dashboard.branch_activity_help') }}</p>
                    @unless($snapshot['globalBalances'])<p role="status" class="rounded-lg bg-stone-50 p-4 text-sm">{{ __('captain_dashboard.global_unavailable') }}</p>@endunless
                    <x-financial.captain-balances :rows="$snapshot['globalBalances'] ? $snapshot['balanceRows'] : $snapshot['rows']" :global="$snapshot['globalBalances']" :inline-currency="true" :empty-label="$snapshot['globalBalances'] ? 'captain_dashboard.no_accounts' : 'financial.ui.no_financial_history'" />
                    <x-financial.account-guide />
                </section>
                <x-mary-card :title="__('captain_dashboard.earnings')" :subtitle="__('captain_dashboard.earnings_help')" class="min-w-0 border border-stone-200">
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">@forelse($snapshot['earnings'] as $earning)<div class="min-w-0 rounded-lg bg-stone-50 p-4"><p class="text-sm break-words">{{ $earning->branch_name }}</p><p class="mt-2 break-all text-xl font-bold"><x-money :amount="$earning->amount" :currency="$earning->currency_code" /></p></div>@empty<p class="text-sm text-gray-600">{{ __('captain_dashboard.no_earnings') }}</p>@endforelse</div>
                </x-mary-card>
                <div class="grid gap-4 sm:grid-cols-2">@foreach(['incentives', 'deductions'] as $category)<x-management.record><h2 class="font-semibold">{{ __('captain_dashboard.'.$category) }}</h2><p class="mt-2 text-sm leading-6 text-gray-600">{{ __('captain_dashboard.'.$category.'_unavailable') }}</p></x-management.record>@endforeach</div>
                <x-mary-card :title="__('captain_dashboard.movements')" :subtitle="__('captain_dashboard.movements_help')" class="min-w-0 border border-stone-200">
                    <div class="divide-y divide-stone-100">@forelse($snapshot['movements'] as $movement)<article class="min-w-0 py-4">
                        <div class="flex flex-wrap justify-between gap-2"><p class="font-semibold break-words">{{ \App\Support\UiLabels::label('financial.events', $movement->event_type) }}</p><p class="break-all"><span class="text-sm">{{ __('financial.directions.'.$movement->direction) }}</span> <x-money :amount="$movement->amount" :currency="$movement->currency_code" /></p></div>
                        <p class="mt-2 text-sm break-words text-gray-600">{{ $movement->branch_name }} · {{ __('financial.accounts.'.$movement->account_kind) }} · <x-ltr>{{ \App\Support\UiDate::datetime($movement->occurred_at) }}</x-ltr></p>
                        <details class="mt-2 text-xs text-gray-600"><summary class="cursor-pointer">{{ __('financial.ui.attribution_details') }}</summary><p class="mt-1 break-all">{{ __('captain_dashboard.source') }}: <x-ltr>{{ $movement->source_type }} #{{ $movement->source_id }}</x-ltr>@if($movement->reversal_of_transaction_id) · {{ __('captain_dashboard.reversal') }} <x-ltr>#{{ $movement->reversal_of_transaction_id }}</x-ltr>@endif</p></details>
                    </article>@empty<p class="py-4 text-sm text-gray-600">{{ __('financial.ui.no_financial_history') }}</p>@endforelse</div>
                </x-mary-card>
            @else<p class="rounded-lg bg-stone-50 p-4 text-sm">{{ __('captain_dashboard.financial_denied') }}</p>@endif
            @if($orderHistory !== null)
                @include('captains._order-history')
            @endif
            @if($snapshot['performance'])
                <section class="space-y-4" aria-labelledby="captain-period-activity">
                    <h2 id="captain-period-activity" class="text-lg font-bold">{{ __('captain_dashboard.activity') }}</h2>
                    <p class="text-sm leading-6 text-gray-600">{{ __('captain_dashboard.activity_help') }} · <x-ltr>{{ \App\Support\UiDate::date($snapshot['activity']['from']) }} — {{ \App\Support\UiDate::date($snapshot['activity']['to']) }}</x-ltr></p>
                    <div class="grid min-w-0 gap-4 sm:grid-cols-3">@foreach($snapshot['activity']['metrics'] as $metric => $count)<x-management.record><h3 class="text-sm text-gray-600">{{ __('captain_dashboard.'.$metric) }}</h3><p class="mt-3 text-3xl font-bold text-brand-primary" data-captain-metric="{{ $metric }}"><x-ltr>{{ $count }}</x-ltr></p></x-management.record>@endforeach</div>
                    @php
                        $trend = $snapshot['activity']['trend'];
                        $settings = ['type' => 'bar', 'data' => ['labels' => array_map(fn($row) => \App\Support\UiDate::date($row['date']), $trend), 'datasets' => [
                            ['label' => __('orders.statuses.DELIVERED'), 'data' => array_column($trend, 'delivered'), 'backgroundColor' => '#164d3b'],
                            ['label' => __('orders.statuses.CANCELLED'), 'data' => array_column($trend, 'cancelled'), 'backgroundColor' => '#9f4b28'],
                        ]]];
                    @endphp
                    <x-dashboard.chart :title="__('captain_dashboard.trend')" :description="__('captain_dashboard.activity_help')" :settings="$settings" :rows="array_map(fn($row) => [\App\Support\UiDate::date($row['date']), $row['delivered'], $row['cancelled']], $trend)" :columns="[__('dashboard.date'), __('orders.statuses.DELIVERED'), __('orders.statuses.CANCELLED')]" :empty="array_sum(array_column($trend, 'delivered')) + array_sum(array_column($trend, 'cancelled')) === 0" />
                </section>
            @else<p class="rounded-lg bg-stone-50 p-4 text-sm">{{ __('captain_dashboard.performance_denied') }}</p>@endif
        </div>
    </x-management.page>
</x-app-layout>
