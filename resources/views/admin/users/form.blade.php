<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $user->exists ? 'Edit user' : 'Create user' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
            @csrf @if($user->exists) @method('PUT') @endif
            <div class="grid gap-5 sm:grid-cols-2"><div class="min-w-0"><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $user->name) }}" required /></div><div class="min-w-0"><x-input-label for="email" value="Email" /><x-text-input id="email" name="email" type="email" class="mt-1 block w-full" value="{{ old('email', $user->email) }}" required /></div></div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div><x-input-label for="password" value="Password" /><x-text-input id="password" name="password" type="password" autocomplete="new-password" class="mt-1 block w-full" :required="! $user->exists" /><p class="mt-1 text-sm text-gray-500">{{ $user->exists ? 'Leave blank to keep the current password.' : 'At least 8 characters.' }}</p></div>
                <div><x-input-label for="password_confirmation" value="Confirm password" /><x-text-input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="mt-1 block w-full" /></div>
            </div>
            @if(auth()->user()->isSuperAdmin())
            <fieldset class="space-y-3"><legend class="font-semibold">Assigned roles</legend>
            @foreach($roles as $role)
                <label class="flex items-start gap-3 break-all"><input class="mt-1 rounded" type="checkbox" name="role_ids[]" value="{{ $role->id }}" @checked(in_array($role->id, old('role_ids', $user->roles->modelKeys())))><span>{{ $role->name }} <span class="text-xs text-gray-500">({{ $role->code }})</span></span></label>
            @endforeach
            </fieldset>
            <fieldset class="space-y-3"><legend class="font-semibold">Assigned branches</legend><p class="text-sm text-gray-500">Select any number of branches. Super administrators automatically access every branch. Inactive branches remain assigned but are unavailable to ordinary users.</p>
            @forelse($branches as $branch)
                <label class="flex items-start gap-3 break-words"><input class="mt-1 rounded" type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array($branch->id, old('branch_ids', $user->branches->modelKeys())))><span>{{ $branch->name }} ({{ $branch->code }}){{ $branch->is_active ? '' : ' — Inactive' }}</span></label>
            @empty<p class="text-sm">Create a branch to assign access.</p>@endforelse
            </fieldset>
            @endif
            <div class="flex flex-wrap items-center gap-4"><x-primary-button>Save user</x-primary-button><a class="text-sm underline" href="{{ route('admin.users.index') }}">Cancel</a></div>
        </form>

    </div>
</x-app-layout>

