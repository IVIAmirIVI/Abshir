<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('customers.title') }}</h2></x-slot>
    <x-management.page>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap items-end justify-between gap-4">
            <form class="flex flex-wrap min-w-0 flex-1 gap-2" method="GET">
                <div class="min-w-0 flex-1 max-w-xl"><x-input-label for="search" :value="__('customers.search')" /><x-text-input id="search" name="search" aria-invalid="{{ $errors->has('search') ? 'true' : 'false' }}" class="mt-1 block w-full" value="{{ request('search') }}"  dir="auto"/></div>
                <x-orders.action variant="secondary" class="self-end">{{ __('common.actions.search') }}</x-orders.action>
            </form>
            @can('customers.create')<a class="btn btn-primary max-w-full whitespace-normal" href="{{ route('customers.create') }}">{{ __('customers.create') }}</a>@endcan
        </div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @forelse($customers as $customer)
                <x-management.record>
                    <div class="flex flex-wrap items-start justify-between gap-3"><h3 class="font-semibold break-words">{{ $customer->name }}</h3><x-orders.indicator :tone="$customer->is_active ? 'complete' : 'blocked'">{{ $customer->is_active ? __('common.operational_active') : __('common.operational_inactive') }}</x-orders.indicator></div>
                    <div class="space-y-1 text-sm text-gray-600">@forelse($customer->phones as $phone)<p class="break-all"><x-ltr>{{ $phone->phone }}</x-ltr>{{ $phone->is_primary ? __('common.operational_primary') : '' }}</p>@empty<p role="status" class="col-span-full rounded-xl border border-dashed border-stone-300 bg-white p-6 text-center text-gray-600">{{ __('customers.no_phones') }}</p>@endforelse</div>
                    <a class="inline-block text-sm underline" href="{{ route('customers.show', $customer) }}">{{ __('customers.view') }}</a>
                </x-management.record>
            @empty<p role="status" class="col-span-full rounded-xl border border-dashed border-stone-300 bg-white p-6 text-center text-gray-600">{{ __('customers.empty') }}</p>@endforelse
        </div>
        {{ $customers->links() }}
    </x-management.page>
</x-app-layout>
