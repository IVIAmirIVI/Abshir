<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Users</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="flex flex-wrap items-center justify-between gap-4"><p class="text-gray-600">Manage accounts, roles and branch access.</p>@can('users.create')<a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('admin.users.create') }}">Create user</a>@endcan</div>
        <div class="grid gap-4 md:grid-cols-2">
        @forelse($users as $user)
            <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3">
                <div class="flex flex-wrap gap-2 justify-between"><h3 class="font-semibold break-all">{{ $user->name }}</h3><span class="text-sm {{ $user->is_active ? 'text-green-700' : 'text-red-700' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</span></div>
                <p class="text-sm text-gray-600 break-all">{{ $user->email }}</p>
                <p class="text-sm break-words"><strong>Roles:</strong> {{ $user->roles->pluck('name')->join(', ') ?: 'No roles assigned' }}</p>
                <p class="text-sm break-words"><strong>Branches:</strong> {{ $user->isSuperAdmin() ? 'All branches' : ($user->branches->pluck('name')->join(', ') ?: 'No branches assigned') }}</p>
                <div class="flex flex-wrap gap-4 items-center">
                @can('users.update')<a class="underline text-sm" href="{{ route('admin.users.edit', $user) }}">Edit user</a>@endcan
                @can($user->is_active ? 'users.deactivate' : 'users.activate')
                <form method="POST" action="{{ route('admin.users.status', $user) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}"><button class="text-sm underline {{ $user->is_active ? 'text-red-700' : 'text-green-700' }}">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                @endcan
                </div>
            </article>
        @empty<p>No users found.</p>@endforelse
        </div>
        {{ $users->links() }}

    </div>
</x-app-layout>

