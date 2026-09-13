<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Customers</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap items-end justify-between gap-4">
            <form class="flex min-w-0 flex-1 gap-2" method="GET">
                <div class="min-w-0 flex-1 max-w-xl"><x-input-label for="search" value="Search by name or phone" /><x-text-input id="search" name="search" class="mt-1 block w-full" value="{{ request('search') }}" /></div>
                <x-primary-button class="self-end">Search</x-primary-button>
            </form>
            @can('customers.create')<a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('customers.create') }}">Create customer</a>@endcan
        </div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @forelse($customers as $customer)
                <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3">
                    <div class="flex items-start justify-between gap-3"><h3 class="font-semibold break-words">{{ $customer->name }}</h3><span class="text-sm {{ $customer->is_active ? 'text-green-700' : 'text-red-700' }}">{{ $customer->is_active ? 'Active' : 'Inactive' }}</span></div>
                    <div class="space-y-1 text-sm text-gray-600">@forelse($customer->phones as $phone)<p class="break-all">{{ $phone->phone }}{{ $phone->is_primary ? ' · Primary' : '' }}</p>@empty<p>No phones</p>@endforelse</div>
                    <a class="inline-block text-sm underline" href="{{ route('customers.show', $customer) }}">View customer</a>
                </article>
            @empty<p>No customers found.</p>@endforelse
        </div>
        {{ $customers->links() }}
    </div>
</x-app-layout>
