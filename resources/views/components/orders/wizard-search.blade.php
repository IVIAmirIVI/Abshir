@props(['model', 'choose', 'sole', 'label', 'query' => ''])
<div data-search-query="{{ $query }}" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false" class="relative min-w-0">
    <input type="text" wire:model.live.debounce.250ms="{{ $model }}" placeholder="{{ $label }}" aria-label="{{ $label }}" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" x-bind:aria-expanded="open" autocomplete="off" dir="auto"
        x-on:focus="open = true" x-on:input="open = true"
        x-on:keydown.enter.prevent="$wire.{{ $sole }}($event.target.value)"
        x-on:keydown.arrow-down.prevent="open = true; $nextTick(() => $refs.results.querySelector('button')?.focus())"
        class="mt-1 block w-full min-w-0 rounded-lg border-gray-300 shadow-sm">
    <div wire:key="wizard-results-{{ $model }}-{{ md5($query) }}" x-cloak x-show="open && $wire.{{ $model }} === $el.parentElement.dataset.searchQuery" x-ref="results" role="listbox" aria-label="{{ $label }}" class="absolute inset-x-0 z-30 mt-1 max-h-56 overflow-y-auto break-words rounded-lg border border-gray-200 bg-white p-1 shadow-lg"
        x-on:keydown.arrow-down.prevent="$event.target.nextElementSibling?.focus()"
        x-on:keydown.arrow-up.prevent="$event.target.previousElementSibling?.focus()">
        {{ $slot }}
    </div>
    <span wire:loading wire:target="{{ $model }},{{ $sole }}" class="text-xs text-gray-500">{{ __('orders.wizard.searching') }}</span>
</div>
