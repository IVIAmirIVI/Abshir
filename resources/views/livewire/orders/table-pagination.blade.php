@if($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('orders.table.pagination') }}" class="flex flex-wrap items-center justify-between gap-2 border-t border-stone-200 px-3 py-2 text-xs">
        <p class="text-gray-500">{{ __('orders.table.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</p>
        <div class="flex flex-wrap items-center gap-1">
            <button type="button" wire:click="previousPage" wire:loading.attr="disabled" @disabled($paginator->onFirstPage()) class="min-h-11 rounded-lg border border-gray-200 px-3 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-40">{{ __('pagination.previous') }}</button>
            <div class="hidden flex-wrap gap-1 sm:flex">
                @foreach($elements as $element)
                    @if(is_string($element))<span class="px-2 py-3" aria-hidden="true">{{ $element }}</span>@endif
                    @if(is_array($element))
                        @foreach($element as $page => $url)
                            <button type="button" wire:key="orders-page-{{ $page }}" wire:click="gotoPage({{ $page }})" wire:loading.attr="disabled" @if($page === $paginator->currentPage()) aria-current="page" @endif aria-label="{{ __('orders.table.go_page', ['page' => $page]) }}" @class(['min-h-11 min-w-11 rounded-lg px-3 disabled:opacity-40', 'bg-brand-primary text-white' => $page === $paginator->currentPage(), 'hover:bg-stone-50' => $page !== $paginator->currentPage()])>{{ $page }}</button>
                        @endforeach
                    @endif
                @endforeach
            </div>
            <button type="button" wire:click="nextPage" wire:loading.attr="disabled" @disabled(!$paginator->hasMorePages()) class="min-h-11 rounded-lg border border-gray-200 px-3 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-40">{{ __('pagination.next') }}</button>
        </div>
    </nav>
@endif
