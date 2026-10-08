<div class="mary-surface min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-8" data-theme="abshir" data-operational-dashboard>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-semibold text-brand-primary">{{ __('dashboard.welcome') }}</p><h1 class="mt-2 text-2xl font-bold">{{ __('dashboard.title') }}</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600">{{ __('dashboard.subtitle') }}</p></div>
        <a href="{{ route('orders.index', array_filter(['branch_id' => $branchId])) }}" class="inline-flex min-h-11 items-center rounded-lg bg-brand-primary px-4 py-2 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('dashboard.all_orders') }}</a>
    </div>
    <section class="flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4" aria-label="{{ __('dashboard.period') }}">
        <label class="block min-w-0 flex-1 sm:flex-none"><span class="mb-1 block text-xs font-semibold">{{ __('dashboard.period') }}</span><select wire:model.live="period" class="min-h-11 w-full rounded-lg border-stone-300 text-sm">@foreach(['today', '7days', '30days'] as $periodOption)<option value="{{ $periodOption }}">{{ __('dashboard.'.$periodOption) }}</option>@endforeach</select></label>
        <label class="block min-w-0 flex-1"><span class="mb-1 block text-xs font-semibold">{{ __('common.terms.branch') }}</span><select wire:model.live="branchId" class="min-h-11 w-full rounded-lg border-stone-300 text-sm"><option value="">{{ __('dashboard.all_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
        <x-mary-button :label="__('dashboard.refresh')" icon="o-arrow-path" wire:click="$refresh" wire:loading.attr="disabled" class="btn-ghost" />
        <p class="w-full text-xs text-gray-600">{{ __('dashboard.timezone') }}@if($snapshot) · <x-ltr>{{ $snapshot['from'] }} — {{ $snapshot['to'] }}</x-ltr>@endif</p>
    </section>
    @error('period')<p role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $message }}</p>@enderror
    <p wire:loading role="status" class="text-sm font-medium text-brand-primary">{{ __('dashboard.loading') }}</p>
    @if($snapshot)
    <div wire:loading.class="opacity-50" aria-live="polite">
        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($snapshot['metrics'] as $metric => $count)
                <x-mary-card :id="'dashboard-kpi-'.$metric" class="min-w-0 border border-stone-200" data-kpi="{{ $metric }}">
                    <h2 class="text-sm font-semibold text-gray-600">{{ __('dashboard.'.$metric) }}</h2>
                    <p class="my-3 text-4xl font-bold tabular-nums text-brand-primary"><x-ltr>{{ $count }}</x-ltr></p>
                    <p class="text-xs leading-5 text-gray-600">{{ __('dashboard.'.$metric.'_help') }}</p>
                </x-mary-card>
            @endforeach
        </div>
        <p class="mt-3 text-xs leading-6 text-gray-600">{{ __('dashboard.current_note') }}</p>
    </div>
    @php
        $labels = array_column($snapshot['trend'], 'date');
        $trendSettings = ['type' => 'line', 'data' => ['labels' => $labels, 'datasets' => [['label' => __('dashboard.orders'), 'data' => array_column($snapshot['trend'], 'orders'), 'borderColor' => '#164d3b', 'backgroundColor' => '#164d3b', 'tension' => 0, 'pointRadius' => 3]]]];
        $outcomeSettings = ['type' => 'bar', 'data' => ['labels' => $labels, 'datasets' => [
            ['label' => __('orders.statuses.DELIVERED'), 'data' => array_column($snapshot['trend'], 'delivered'), 'backgroundColor' => '#164d3b'],
            ['label' => __('orders.statuses.CANCELLED'), 'data' => array_column($snapshot['trend'], 'cancelled'), 'backgroundColor' => '#9f4b28'],
        ]]];
        $statusSettings = ['type' => 'bar', 'horizontal' => true, 'data' => ['labels' => array_column($snapshot['distribution'], 'label'), 'datasets' => [['label' => __('dashboard.count'), 'data' => array_column($snapshot['distribution'], 'count'), 'backgroundColor' => '#486957']]]];
    @endphp
    <div class="grid min-w-0 gap-4 xl:grid-cols-2" wire:loading.class="opacity-50">
        <x-dashboard.chart :title="__('dashboard.trend')" :description="__('dashboard.trend_help')" :settings="$trendSettings" :rows="array_map(fn($row) => [$row['date'], $row['orders']], $snapshot['trend'])" :columns="[__('dashboard.date'), __('dashboard.count')]" :empty="array_sum(array_column($snapshot['trend'], 'orders')) === 0" />
        <x-dashboard.chart :title="__('dashboard.outcomes')" :description="__('dashboard.outcomes_help')" :settings="$outcomeSettings" :rows="array_map(fn($row) => [$row['date'], $row['delivered'], $row['cancelled']], $snapshot['trend'])" :columns="[__('dashboard.date'), __('orders.statuses.DELIVERED'), __('orders.statuses.CANCELLED')]" :empty="$snapshot['metrics']['delivered'] + $snapshot['metrics']['cancelled'] === 0" />
    </div>
    <div class="grid min-w-0 gap-4 xl:grid-cols-2">
        <x-dashboard.chart :title="__('dashboard.distribution')" :description="__('dashboard.distribution_help')" :settings="$statusSettings" :rows="array_map(fn($row) => [$row['label'], $row['count']], $snapshot['distribution'])" :columns="[__('orders.table.status'), __('dashboard.count')]" :empty="array_sum(array_column($snapshot['distribution'], 'count')) === 0" horizontal />
        <x-mary-card :title="__('dashboard.distribution')" :subtitle="__('dashboard.distribution_help')" id="dashboard-status-links" class="min-w-0 border border-stone-200">
            <ul class="space-y-1">@foreach($snapshot['distribution'] as $row)<li><a href="{{ route('orders.index', array_filter(['branch_id' => $branchId, 'operational_status' => $row['status']])) }}" class="flex min-h-11 items-center justify-between gap-3 rounded-lg px-3 text-sm hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary"><span>{{ $row['label'] }}</span><x-ltr class="font-bold text-brand-primary">{{ $row['count'] }}</x-ltr></a></li>@endforeach</ul>
        </x-mary-card>
    </div>
    <x-mary-card :title="__('dashboard.follow_up')" :subtitle="__('dashboard.follow_up_help')" id="dashboard-follow-up" class="min-w-0 border border-stone-200">
        <div class="divide-y divide-stone-100">
            @forelse($snapshot['followUp'] as $order)
            <article wire:key="dashboard-order-{{ $order->id }}" class="flex flex-wrap items-center justify-between gap-3 py-4" data-attention-order="{{ $order->id }}">
                <div class="min-w-0 flex-1"><p class="flex flex-wrap gap-2 text-sm font-bold"><x-ltr>{{ $order->order_number }}</x-ltr><span>{{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status) }}</span></p><p class="mt-1 break-words text-sm text-gray-600">{{ $order->customer_name_snapshot }} · {{ $order->branch->name }}</p><p class="mt-2 text-xs font-semibold text-brand-primary">{{ __('dashboard.'.$order->attention_action) }}</p></div>
                <a href="{{ route('orders.workflow', $order) }}" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 px-3 text-sm font-semibold text-brand-primary hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('dashboard.workflow') }}</a>
            </article>
            @empty<p class="py-6 text-sm text-gray-600">{{ __('dashboard.no_attention') }}</p>@endforelse
        </div>
    </x-mary-card>
    @endif
</div>
