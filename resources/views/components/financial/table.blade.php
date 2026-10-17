@props(['label'])
<p class="text-xs text-gray-600">{{ __('financial.ui.table_scroll_help') }}</p>
<div {{ $attributes->class(['financial-table-wrap']) }} role="region" aria-label="{{ $label }}" tabindex="0">{{ $slot }}</div>
