<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-4 px-4 py-5 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-brand-primary">{{ __('orders.ui_title') }}</h1>
                <p class="mt-1 text-xs text-gray-500">{{ __('orders.ui_index_help') }}</p>
            </div>
            @can('orders.create')
                <a class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-brand-primary px-4 py-2 text-sm font-semibold text-white hover:bg-brand-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary" href="{{ route('orders.create') }}"><span aria-hidden="true">+</span>{{ __('orders.ui_create') }}</a>
            @endcan
        </div>
        <livewire:orders.orders-table />
    </div>
</x-app-layout>
