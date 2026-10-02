<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('admin.ui.roles') }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="flex flex-wrap items-center justify-between gap-4"><p class="text-gray-600">{{ __('admin.ui.core_roles_are_protected_custom_roles_provide_flexible_access') }}</p><a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('admin.roles.create') }}">{{ __('admin.ui.create_role') }}</a></div>
        <div class="grid gap-4 md:grid-cols-2">
        @foreach($roles as $role)
            <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3">
                <h3 class="font-semibold break-all">{{ \App\Support\UiLabels::role($role->code, $role->name) }}</h3><p class="text-sm break-all"><x-ltr>{{ $role->code }}</x-ltr>{{ $role->is_system ? ' · '.__('admin.ui.system_role') : '' }}</p>
                <p class="text-sm text-gray-600 break-words">{{ $role->code === 'SUPER_ADMIN' ? __('admin.ui.unrestricted_access_including_future_permissions') : ($role->permissions->map(fn ($permission) => \App\Support\UiLabels::permission($permission->code, $permission->name))->join('، ') ?: __('admin.ui.no_permissions_assigned')) }}</p>
                <a class="inline-block text-sm underline" href="{{ route('admin.roles.edit', $role) }}">{{ $role->is_system ? __('admin.ui.view_permissions') : __('admin.ui.edit_role_and_permissions') }}</a>
            </article>
        @endforeach
        </div>

    </div>
</x-app-layout>

