<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('captains.ui_captains') }}</h2></x-slot>
    <x-management.page>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap items-end justify-between gap-4">
            <form class="flex flex-wrap min-w-0 flex-1 gap-2" method="GET"><div class="min-w-0 flex-1 max-w-xl"><x-input-label for="search" :value="__('captains.ui_search_by_name_or_employee_code')" /><x-text-input id="search" name="search" aria-invalid="{{ $errors->has('search') ? 'true' : 'false' }}" class="mt-1 block w-full" value="{{ request('search') }}" dir="auto" /></div><x-orders.action variant="secondary" class="self-end">{{ __('common.actions.search') }}</x-orders.action></form>
            @can('captains.create')<a class="btn btn-primary max-w-full whitespace-normal" href="{{ route('captains.create') }}">{{ __('captains.ui_create_captain') }}</a>@endcan
        </div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @forelse($captains as $captain)<x-management.record><div class="flex flex-wrap justify-between gap-3"><h3 class="font-semibold break-words">{{ $captain->user->name }}</h3><x-orders.indicator>{{ \App\Support\UiLabels::label('common.captain_statuses', $captain->operational_status) }}</x-orders.indicator></div><p class="text-sm">{{ __('captains.ui_account') }} <x-orders.indicator :tone="$captain->user->is_active ? 'complete' : 'blocked'">{{ $captain->user->is_active ? __('common.account_states.active') : __('common.account_states.inactive') }}</x-orders.indicator></p><p class="text-sm text-gray-600 break-words">{{ __('captains.ui_home') }} {{ $captain->homeBranch->name }}</p><p class="text-sm break-all"><x-ltr>{{ $captain->employee_code ?: __('captains.ui_no_employee_code') }}</x-ltr></p>@can('captain_ratings.view_summary')<p class="text-sm">{{ __('captains.ui_ratings') }} {{ $captain->ratings_count }} · {{ __('captains.ui_average') }} {{ $captain->ratings_count ? number_format((float) $captain->ratings_average, 2) : '—' }}</p>@endcan<a class="inline-block text-sm underline" href="{{ route('captains.show', $captain) }}">{{ __('captains.ui_view_captain') }}</a></x-management.record>@empty<p role="status" class="col-span-full rounded-xl border border-dashed border-stone-300 bg-white p-6 text-center text-gray-600">{{ __('captains.ui_no_captains_found') }}</p>@endforelse
        </div>
        {{ $captains->links() }}
    </x-management.page>
</x-app-layout>
