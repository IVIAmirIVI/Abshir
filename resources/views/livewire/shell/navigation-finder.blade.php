<div class="mary-surface" data-theme="abshir" data-shell-finder>
    <x-mary-button :label="__('shell.find_page')" icon="o-magnifying-glass" wire:click="$set('open', true)" class="btn-ghost btn-sm" aria-haspopup="dialog" />
    <x-mary-modal wire:model="open" persistent aria-labelledby="shell-finder-title" @keydown.escape.stop="$wire.close()" @click.self="$wire.close()" class="!animate-none">
        <div id="shell-page-finder">
            <h2 id="shell-finder-title" class="mb-4 text-lg font-bold">{{ __('shell.find_page') }}</h2>
            <x-mary-input :label="__('shell.page_search')" :placeholder="__('shell.page_search_placeholder')" :aria-label="__('shell.page_search')" wire:model.live.debounce.200ms="search" maxlength="100" autocomplete="off" dir="auto" />
            <div class="mt-4 max-h-72 space-y-1 overflow-y-auto" aria-live="polite">
                @forelse($this->links as $link)
                    <a wire:key="shell-find-{{ $link['route'] }}" href="{{ route($link['route']) }}" class="block rounded-lg px-3 py-3 text-sm text-brand-primary hover:bg-stone-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ $link['label'] }}</a>
                @empty
                    <p class="py-4 text-sm text-gray-600">{{ __('shell.no_pages') }}</p>
                @endforelse
            </div>
        </div>
        <x-slot:actions>
            <x-mary-button :label="__('shell.close')" wire:click="close" class="btn-primary" />
        </x-slot:actions>
    </x-mary-modal>
</div>
