<div class="mary-surface min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-8" data-management-reports>
    <div class="flex flex-wrap justify-between gap-3"><h1 class="text-2xl font-bold text-brand-primary">{{ __('reports.title') }}</h1><a class="btn btn-outline" href="{{ route('dashboard') }}">{{ __('dashboard.title') }}</a></div>
    <form wire:submit="applyFilters" class="grid gap-4 rounded-xl border border-stone-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
        <label>{{ __('reports.from') }}<input type="text" dir="ltr" placeholder="YYYY-MM-DD" wire:model="from" class="input input-bordered w-full" maxlength="10" aria-describedby="report-date-help"></label>
        <label>{{ __('reports.to') }}<input type="text" dir="ltr" placeholder="YYYY-MM-DD" wire:model="to" class="input input-bordered w-full" maxlength="10" aria-describedby="report-date-help"></label>
        <label>{{ __('common.terms.branch') }}<select wire:model="branchId" class="select select-bordered w-full"><option value="">{{ __('dashboard.all_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
        <label>{{ __('common.terms.currency') }}<select wire:model="currencyId" class="select select-bordered w-full"><option value="">{{ __('reports.all_currencies') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></label>
        <button class="btn btn-primary self-end" wire:loading.attr="disabled">{{ __('reports.apply') }}</button>
        <p id="report-date-help" class="text-xs text-gray-600 sm:col-span-2 lg:col-span-5">{{ __('reports.date_help') }}</p>
    </form>
    @if($errors->any())<ul class="alert alert-error" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <p wire:loading role="status">{{ __('dashboard.loading') }}</p>
    @if($report)
        <p class="alert text-sm">{{ __('reports.boundaries') }}</p>
        @php
            $operations = $report['operations'];
            $reportFilters = ['from' => $from, 'to' => $to, 'branch_id' => $branchId, 'currency_id' => $currencyId];
        @endphp
        @if(!$operations['available'])
            <p role="status" class="alert">{{ __('reports.unavailable') }}</p>
        @else
            <p class="text-sm leading-7">{{ __('reports.operational_help') }}</p>
            <div class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach(['created','delivered','cancelled','single','shared','unassigned','one_time_shops'] as $metric)
                    <x-mary-card :title="__('reports.metrics.'.$metric)" class="min-w-0 border border-stone-200"><p class="text-3xl font-bold text-brand-primary"><x-ltr>{{ $operations[$metric] }}</x-ltr></p>@if($metric !== 'one_time_shops')<a class="link text-sm" href="{{ route('reports.detail', ['type' => $metric] + $reportFilters) }}">{{ __('reports.details') }}</a>@endif</x-mary-card>
                @endforeach
                <x-mary-card :title="__('reports.duration')" class="min-w-0 border border-stone-200"><p class="text-2xl font-bold"><x-ltr>{{ $operations['duration']->minutes ?? '—' }}</x-ltr></p><p class="text-xs">{{ __('reports.duration_help', ['count' => $operations['duration']->samples]) }}</p></x-mary-card>
            </div>
            @php
                $settings = ['type' => 'line', 'data' => ['labels' => array_column($operations['trend'], 'date'), 'datasets' => []]];
                foreach (['created' => '#164d3b', 'delivered' => '#486957', 'cancelled' => '#9f4b28'] as $metric => $color) {
                    $settings['data']['datasets'][] = ['label' => __('reports.metrics.'.$metric), 'data' => array_column($operations['trend'], $metric), 'borderColor' => $color, 'tension' => 0];
                }
            @endphp
            <x-dashboard.chart :title="__('reports.trend')" :description="__('reports.operational_help')" :settings="$settings" :rows="array_map(fn($r) => array_values($r), $operations['trend'])" :columns="[__('dashboard.date'), __('reports.metrics.created'), __('reports.metrics.delivered'), __('reports.metrics.cancelled')]" :empty="$operations['created'] + $operations['delivered'] + $operations['cancelled'] === 0" />
            <x-mary-card :title="__('reports.statuses')" class="border border-stone-200">
                @forelse($operations['statuses'] as $status => $total)<p class="flex justify-between gap-3 py-2"><span>{{ __('orders.statuses.'.$status) }}</span><x-ltr>{{ $total }}</x-ltr></p>@empty<p>{{ __('reports.empty') }}</p>@endforelse
                <p class="mt-3 text-xs">{{ __('reports.version_help') }}</p>
                @foreach($operations['versions'] as $version)<p><x-ltr>V{{ $version->version }}: {{ $version->total }}</x-ltr></p>@endforeach
            </x-mary-card>
            <div class="grid gap-4 xl:grid-cols-2">
                @if($operations['captains'])
                    <x-mary-card :title="__('reports.captains')" :subtitle="__('reports.participation_help')" class="min-w-0 border border-stone-200">
                        <a class="link text-sm" href="{{ route('reports.detail', ['type' => 'captains'] + $reportFilters) }}">{{ __('reports.details_export') }}</a>
                        <table class="w-full table-fixed text-sm text-start"><thead><tr><th>{{ __('common.terms.captain') }}</th><th>{{ __('reports.participations') }}</th><th>{{ __('reports.delivered_cohort') }}</th></tr></thead><tbody>
                        @forelse($operations['captains'] as $row)<tr class="border-t"><td class="break-words py-3"><a class="link" href="{{ route('captains.show', $row->id) }}">{{ $row->name }} <x-ltr>#{{ $row->id }}</x-ltr></a></td><td><x-ltr>{{ $row->total }}</x-ltr></td><td><x-ltr>{{ $row->delivered }}</x-ltr></td></tr>@empty<tr><td colspan="3">{{ __('reports.empty') }}</td></tr>@endforelse
                        </tbody></table>{{ $operations['captains']->links() }}
                    </x-mary-card>
                @endif
                @if($operations['shops'])
                    <x-mary-card :title="__('reports.shops')" :subtitle="__('reports.participation_help')" class="min-w-0 border border-stone-200">
                        <a class="link text-sm" href="{{ route('reports.detail', ['type' => 'shops'] + $reportFilters) }}">{{ __('reports.details_export') }}</a>
                        <table class="w-full table-fixed text-sm text-start"><thead><tr><th>{{ __('common.terms.shop') }}</th><th>{{ __('reports.order_count') }}</th></tr></thead><tbody>
                        @forelse($operations['shops'] as $row)<tr class="border-t"><td class="break-words py-3"><a class="link" href="{{ route('shops.show', $row->id) }}">{{ $row->name }}</a></td><td><x-ltr>{{ $row->total }}</x-ltr></td></tr>@empty<tr><td colspan="2">{{ __('reports.empty') }}</td></tr>@endforelse
                        </tbody></table>{{ $operations['shops']->links() }}
                    </x-mary-card>
                @endif
            </div>
        @endif
        <h2 class="text-xl font-bold">{{ __('reports.financial_title') }}</h2>
        @forelse($report['financial'] as $group => $source)
            <x-mary-card :title="__('reports.groups.'.$group)" :subtitle="__('reports.help.'.$group)" class="min-w-0 border border-stone-200">
                @if(!$source['available'])<p role="status">{{ __('reports.unavailable') }}</p>
                @else
                    @forelse($source['rows'] as $row)
                        <section class="my-3 rounded-lg bg-stone-50 p-4"><h3 class="font-bold"><x-ltr>{{ $row['code'] }}</x-ltr></h3><dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($row as $key => $amount)@if(!in_array($key, ['currency_id','code'], true))
                            <div><dt class="text-xs">{{ str_starts_with($key,'CAPTAIN_') ? __('financial.accounts.'.$key) : __('reports.values.'.$key) }}</dt><dd class="mt-1 font-semibold"><x-money :amount="$amount" :currency="$row['code']" /></dd></div>
                        @endif @endforeach
                        </dl></section>
                    @empty<p>{{ __('reports.empty') }}</p>@endforelse
                    @if($group === 'collections' && $source['versions'])
                        <p class="text-xs">{{ __('reports.collection_versions') }}</p>
                        @foreach($source['versions'] as $row)<p class="py-1"><x-ltr>V{{ $row['version'] }}</x-ltr> · {{ __('financial.collection_methods.'.$row['collection_method']) }} · <x-money :amount="$row['amount']" :currency="$row['code']" /></p>@endforeach
                    @endif
                @endif
                @if($source['available'])
                <a class="link text-sm" href="{{ route('reports.detail', ['type' => $group] + $reportFilters) }}">{{ __('reports.details_export') }}</a>
                @endif
            </x-mary-card>
        @empty<p>{{ __('reports.no_financial_access') }}</p>@endforelse
    @endif
</div>
