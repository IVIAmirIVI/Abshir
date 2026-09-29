<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Captain financial history</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4">
        <p class="text-sm text-gray-600">Captains with financial history in your accessible Branches remain visible after transfer.</p>
        <div class="rounded-lg bg-white shadow divide-y">
            @forelse($captains as $captain)
                <a class="block p-4 underline" href="{{ route('captains.financials.show', $captain) }}">{{ $captain->user->name }} · {{ $captain->employee_code }}</a>
            @empty<p class="p-4">No accessible Captain financial history.</p>@endforelse
        </div>
        {{ $captains->links() }}
    </div>
</x-app-layout>
