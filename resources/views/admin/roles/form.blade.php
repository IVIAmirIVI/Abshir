<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $role->exists ? ($role->is_system ? 'View system role' : 'Edit role') : 'Create role' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        @if($role->is_system)<p class="rounded-lg bg-blue-50 p-4 text-blue-800">This core role is protected. {{ $role->code === 'SUPER_ADMIN' ? 'Super administrators automatically pass all authorization checks.' : 'Create a custom role to change access.' }}</p>@endif
        <form class="max-w-4xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}">
            @csrf @if($role->exists) @method('PUT') @endif
            <fieldset @disabled($role->is_system) class="space-y-6 min-w-0">
                <div class="grid gap-5 sm:grid-cols-2"><div class="min-w-0"><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $role->name) }}" required /></div><div class="min-w-0"><x-input-label for="code" value="Role code" /><x-text-input id="code" name="code" type="text" class="mt-1 block w-full" value="{{ old('code', $role->code) }}" required /></div></div>
                <p class="text-sm text-gray-500">Use a stable code such as BRANCH_MANAGER. Permission changes affect every user assigned to this role.</p>
                <div class="grid gap-6 sm:grid-cols-2">
                @foreach($permissions as $group => $items)
                    <fieldset class="min-w-0 space-y-3 rounded-md border p-4"><legend class="px-1 font-semibold">{{ ucfirst(str_replace('_', ' ', $group)) }}</legend>
                    @foreach($items as $permission)
                        <label class="flex gap-3 items-start"><input class="mt-1 rounded" type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->code === 'SUPER_ADMIN' || in_array($permission->id, old('permission_ids', $role->permissions->modelKeys())))><span class="text-sm break-all">{{ $permission->code }}</span></label>
                    @endforeach
                    </fieldset>
                @endforeach
                </div>
                @unless($role->is_system)<div class="flex flex-wrap items-center gap-4"><x-primary-button>Save role</x-primary-button><a class="text-sm underline" href="{{ route('admin.roles.index') }}">Cancel</a></div>@endunless
            </fieldset>
            @if($role->is_system)<a class="inline-block underline text-sm" href="{{ route('admin.roles.index') }}">Back to roles</a>@endif
        </form>

    </div>
</x-app-layout>

