<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('admin.ui.branches') }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="flex flex-wrap items-center justify-between gap-4"><p class="text-gray-600">{{ __('admin.ui.branches_available_to_your_account') }}</p>@can('branches.create')<a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('admin.branches.create') }}">{{ __('admin.ui.create_branch') }}</a>@endcan</div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse($branches as $branch)
            <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3">
                <h3 class="font-semibold break-all">{{ $branch->name }}</h3>
                <p class="text-sm break-all"><x-ltr>{{ $branch->code }}</x-ltr> · <span class="{{ $branch->is_active ? 'text-green-700' : 'text-red-700' }}">{{ $branch->is_active ? __('admin.ui.active') : __('admin.ui.inactive') }}</span></p>
                <p class="text-sm text-gray-600 break-words">{{ $branch->address ?: __('admin.ui.no_address_provided') }}</p><p class="text-sm break-all"><x-ltr>{{ $branch->phone }}</x-ltr></p>
                <div class="flex flex-wrap gap-4 items-center">
                    @can('branches.update')<a class="underline text-sm" href="{{ route('admin.branches.edit', $branch) }}">{{ __('admin.ui.edit_branch') }}</a>@endcan
                    @can($branch->is_active ? 'branches.deactivate' : 'branches.activate')
                    <form method="POST" action="{{ route('admin.branches.status', $branch) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $branch->is_active ? 0 : 1 }}"><button class="underline text-sm">{{ $branch->is_active ? __('admin.ui.deactivate') : __('admin.ui.activate') }}</button></form>
                    @endcan
                </div>
            </article>
        @empty<p>{{ __('admin.ui.no_branches_are_available') }}</p>@endforelse
        </div>
        {{ $branches->links() }}

    </div>
</x-app-layout>

