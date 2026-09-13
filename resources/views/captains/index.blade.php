<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Captains</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap items-end justify-between gap-4">
            <form class="flex min-w-0 flex-1 gap-2" method="GET"><div class="min-w-0 flex-1 max-w-xl"><x-input-label for="search" value="Search by name or employee code" /><x-text-input id="search" name="search" class="mt-1 block w-full" value="{{ request('search') }}" /></div><x-primary-button class="self-end">Search</x-primary-button></form>
            @can('captains.create')<a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('captains.create') }}">Create captain</a>@endcan
        </div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @forelse($captains as $captain)<article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3"><div class="flex justify-between gap-3"><h3 class="font-semibold break-words">{{ $captain->user->name }}</h3><span class="text-sm">{{ $captain->operational_status->label() }}</span></div><p class="text-sm text-gray-600 break-words">Home: {{ $captain->homeBranch->name }}</p><p class="text-sm break-all">{{ $captain->employee_code ?: 'No employee code' }}</p><a class="inline-block text-sm underline" href="{{ route('captains.show', $captain) }}">View captain</a></article>@empty<p>No captains found.</p>@endforelse
        </div>
        {{ $captains->links() }}
    </div>
</x-app-layout>
