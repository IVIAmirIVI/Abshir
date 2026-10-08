@props(['settings', 'title', 'description', 'rows', 'columns', 'empty' => false, 'horizontal' => false])
<x-mary-card :title="$title" :subtitle="$description" class="min-w-0 border border-stone-200" :id="md5($title)">
    @if($empty)
        <p class="rounded-lg bg-stone-50 p-5 text-sm text-gray-600">{{ __('dashboard.no_chart_data') }}</p>
    @else
        <div wire:key="dashboard-chart-{{ md5($title.json_encode($settings)) }}" x-data="operationalChart(@js($settings))">
            <div wire:ignore class="relative min-w-0" style="height: {{ $horizontal ? '22rem' : '16rem' }}">
                <canvas x-ref="canvas" role="img" aria-label="{{ $title }}">{{ __('dashboard.show_numbers') }}</canvas>
            </div>
            <p x-cloak x-show="unavailable" role="status" class="mt-3 text-sm text-gray-600">{{ __('dashboard.chart_unavailable') }}</p>
        </div>
    @endif
    <details class="mt-4 border-t border-stone-200 pt-3">
        <summary class="cursor-pointer rounded text-sm font-medium text-brand-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('dashboard.show_numbers') }}</summary>
        <div class="mt-3">
            <table class="w-full table-fixed text-start text-xs sm:text-sm">
                <caption class="sr-only">{{ $title }}</caption>
                <thead><tr>@foreach($columns as $column)<th scope="col" class="break-words px-1 py-2 text-start">{{ $column }}</th>@endforeach</tr></thead>
                <tbody>@foreach($rows as $row)<tr class="border-t border-stone-100">@foreach($row as $value)<td class="break-words px-1 py-2"><bdi dir="auto">{{ $value }}</bdi></td>@endforeach</tr>@endforeach</tbody>
            </table>
        </div>
    </details>
</x-mary-card>
