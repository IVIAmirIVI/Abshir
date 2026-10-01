<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('customers.title') }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap items-end justify-between gap-4">
            <form class="flex flex-wrap min-w-0 flex-1 gap-2" method="GET">
                <div class="min-w-0 flex-1 max-w-xl"><x-input-label for="search" :value="__('customers.search')" /><x-text-input id="search" name="search" class="mt-1 block w-full" value="{{ request('search') }}"  dir="auto"/></div>
                <x-primary-button class="self-end">{{ __('common.actions.search') }}</x-primary-button>
            </form>
            @can('customers.create')<a class="rounded-md bg-brand-primary px-4 py-2 text-white" href="{{ route('customers.create') }}">{{ __('customers.create') }}</a>@endcan
        </div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @forelse($customers as $customer)
                <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3"><h3 class="font-semibold break-words">{{ $customer->name }}</h3><span class="text-sm {{ $customer->is_active ? 'text-green-700' : 'text-red-700' }}">{{ $customer->is_active ? __('common.operational_active') : __('common.operational_inactive') }}</span></div>
                    <div class="space-y-1 text-sm text-gray-600">@forelse($customer->phones as $phone)<p class="break-all"><x-ltr>{{ $phone->phone }}</x-ltr>{{ $phone->is_primary ? __('common.operational_primary') : '' }}</p>@empty<p>{{ __('customers.no_phones') }}</p>@endforelse</div>
                    <a class="inline-block text-sm underline" href="{{ route('customers.show', $customer) }}">{{ __('customers.view') }}</a>
                </article>
            @empty<p>{{ __('customers.empty') }}</p>@endforelse
        </div>
        {{ $customers->links() }}
    </div>
</x-app-layout>
