<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('financial.ui.captain_history') }}</h2></x-slot>
    <div class="financial-ui max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4">
        <p class="text-sm text-gray-600">{{ __('financial.ui.history_transfer_help') }}</p>
        <div class="rounded-lg bg-white shadow divide-y">
            @forelse($captains as $captain)
                <a class="block p-4 underline" href="{{ route('captains.financials.show', $captain) }}">{{ $captain->user->name }} · <x-ltr>{{ $captain->employee_code }}</x-ltr></a>
            @empty<p class="p-4">{{ __('financial.ui.no_captain_history') }}</p>@endforelse
        </div>
        {{ $captains->links() }}
    </div>
</x-app-layout>
