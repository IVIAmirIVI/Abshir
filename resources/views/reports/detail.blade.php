<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('reports.title') }} — {{ __('reports.detail_titles.'.$report['type']) }}</h2></x-slot>
    <div class="mary-surface min-w-0 space-y-5 px-4 py-6 sm:px-6 lg:px-8" data-report-detail>
        <div class="flex flex-wrap gap-3">
            <a class="btn btn-outline" href="{{ route('reports.index', $report['filters']) }}">{{ __('reports.back_summary') }}</a>
            @if($rows !== null)<a class="btn btn-primary" href="{{ route('reports.export', ['type' => $report['type']] + $report['filters']) }}">{{ __('reports.export_csv') }}</a>@endif
            @if($report['type'] === 'commissions')<a class="btn btn-outline" href="{{ route('reports.detail', ['type' => 'commission_balances'] + $report['filters']) }}">{{ __('reports.detail_titles.commission_balances') }}</a>@endif
            @if($report['type'] === 'balances')<a class="btn btn-outline" href="{{ route('reports.detail', ['type' => 'ledger'] + $report['filters']) }}">{{ __('reports.detail_titles.ledger') }}</a>@endif
        </div>
        <form method="GET" action="{{ route('reports.detail', $report['type']) }}" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
            <label>{{ __('reports.from') }}<input class="input input-bordered w-full" type="text" name="from" dir="ltr" placeholder="YYYY-MM-DD" maxlength="10" value="{{ $report['filters']['from'] }}"></label>
            <label>{{ __('reports.to') }}<input class="input input-bordered w-full" type="text" name="to" dir="ltr" placeholder="YYYY-MM-DD" maxlength="10" value="{{ $report['filters']['to'] }}"></label>
            <label>{{ __('common.terms.branch') }}<select name="branch_id" class="select select-bordered w-full"><option value="">{{ __('dashboard.all_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)$branch->id === (string)($report['filters']['branch_id'] ?? ''))>{{ $branch->name }}</option>@endforeach</select></label>
            <label>{{ __('common.terms.currency') }}<select name="currency_id" class="select select-bordered w-full"><option value="">{{ __('reports.all_currencies') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string)$currency->id === (string)($report['filters']['currency_id'] ?? ''))>{{ $currency->code }}</option>@endforeach</select></label>
            <button class="btn btn-primary self-end">{{ __('reports.apply') }}</button>
            <p class="text-xs sm:col-span-2 lg:col-span-5">{{ __('reports.date_help') }}</p>
        </form>
        <p class="alert text-sm">{{ __('reports.boundaries') }}</p>
        <p class="text-sm">{{ __('reports.definitions.'.$report['definition']) }} · {{ __('reports.sources.'.$report['source']) }}</p>
        <p class="text-sm">{{ __('reports.'.$report['help']) }}</p>
        @if($rows === null)
            <p role="status" class="alert">{{ __('reports.unavailable') }}</p>
        @else
            <p class="text-xs">{{ __('reports.csv_help') }}</p>
            <p>{{ __('reports.row_count', ['count' => $rows->total()]) }}</p>
            <div class="max-w-full overflow-x-auto rounded-xl border bg-white">
                <table class="table text-start text-sm"><thead><tr>@foreach($report['columns'] as $field => $_)<th class="whitespace-nowrap">{{ __('reports.columns.'.$field) }}</th>@endforeach<th>{{ __('reports.source_records') }}</th></tr></thead><tbody>
                @forelse($rows as $row)
                    <tr>@foreach($report['columns'] as $field => $_)<td class="max-w-xs break-words">@if(in_array($field, ['name','status','event_type','collection_method','kind','account_kind'], true)){{ match($field) { 'status' => __('orders.statuses.'.$row->status), 'event_type' => __('financial.events.'.$row->event_type), 'collection_method' => __('financial.collection_methods.'.$row->collection_method), 'kind' => __('commissions.kinds.'.$row->kind), 'account_kind' => __('financial.accounts.'.$row->account_kind), default => $details->cell($row,$field) } }}@else<x-ltr>{{ $details->cell($row,$field) }}</x-ltr>@endif</td>@endforeach
                    <td>@if($link = $details->sourceLink($report['type'], $row, $report['can_open_invoice']))<a class="link whitespace-nowrap" href="{{ $link }}">{{ __('reports.source_records') }}</a>@endif</td></tr>
                @empty<tr><td colspan="{{ count($report['columns']) + 1 }}">{{ __('reports.empty') }}</td></tr>@endforelse
                </tbody></table>
            </div>
            {{ $rows->links() }}
        @endif
    </div>
</x-app-layout>
