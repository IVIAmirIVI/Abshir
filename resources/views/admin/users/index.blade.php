<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('admin.ui.users') }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="flex flex-wrap items-center justify-between gap-4"><p class="text-gray-600">{{ __('admin.ui.manage_accounts_roles_and_branch_access') }}</p>@can('users.create')<a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('admin.users.create') }}">{{ __('admin.ui.create_user') }}</a>@endcan</div>
        <div class="grid gap-4 md:grid-cols-2">
        @forelse($users as $user)
            <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3">
                <div class="flex flex-wrap gap-2 justify-between"><h3 class="font-semibold break-all">{{ $user->name }}</h3><span class="text-sm {{ $user->is_active ? 'text-green-700' : 'text-red-700' }}">{{ $user->is_active ? __('admin.ui.active') : __('admin.ui.inactive') }}</span></div>
                <p class="text-sm text-gray-600 break-all"><x-ltr>{{ $user->email }}</x-ltr></p>
                <p class="text-sm break-words"><strong>{{ __('admin.ui.roles') }}</strong> {{ $user->roles->map(fn ($role) => \App\Support\UiLabels::role($role->code, $role->name))->join('، ') ?: __('admin.ui.no_roles_assigned') }}</p>
                <p class="text-sm break-words"><strong>{{ __('admin.ui.branches') }}</strong> {{ $user->isSuperAdmin() ? __('admin.ui.all_branches') : ($user->branches->pluck('name')->join(', ') ?: __('admin.ui.no_branches_assigned')) }}</p>
                <div class="flex flex-wrap gap-4 items-center">
                @can('users.update')<a class="underline text-sm" href="{{ route('admin.users.edit', $user) }}">{{ __('admin.ui.edit_user') }}</a>@endcan
                @can($user->is_active ? 'users.deactivate' : 'users.activate')
                <form method="POST" action="{{ route('admin.users.status', $user) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}"><button class="text-sm underline {{ $user->is_active ? 'text-red-700' : 'text-green-700' }}">{{ $user->is_active ? __('admin.ui.deactivate') : __('admin.ui.activate') }}</button></form>
                @endcan
                </div>
            </article>
        @empty<p>{{ __('admin.ui.no_users_found') }}</p>@endforelse
        </div>
        {{ $users->links() }}

    </div>
</x-app-layout>

