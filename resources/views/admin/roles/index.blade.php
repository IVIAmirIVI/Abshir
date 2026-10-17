<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('admin.ui.roles') }}</h2></x-slot>
    <x-management.page>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="flex flex-wrap items-center justify-between gap-4"><p class="text-gray-600">{{ __('admin.ui.core_roles_are_protected_custom_roles_provide_flexible_access') }}</p><a class="btn btn-primary max-w-full whitespace-normal" href="{{ route('admin.roles.create') }}">{{ __('admin.ui.create_role') }}</a></div>
        <div class="grid gap-4 md:grid-cols-2">
        @foreach($roles as $role)
            <x-management.record>
                <h3 class="font-semibold break-all">{{ \App\Support\UiLabels::role($role->code, $role->name) }}</h3><p class="text-sm break-all"><x-ltr>{{ $role->code }}</x-ltr>{{ $role->is_system ? ' · '.__('admin.ui.system_role') : '' }}</p>
                <p class="text-sm text-gray-600 break-words">{{ $role->code === 'SUPER_ADMIN' ? __('admin.ui.unrestricted_access_including_future_permissions') : ($role->permissions->map(fn ($permission) => \App\Support\UiLabels::permission($permission->code, $permission->name))->join('، ') ?: __('admin.ui.no_permissions_assigned')) }}</p>
                <a class="inline-block text-sm underline" href="{{ route('admin.roles.edit', $role) }}">{{ $role->is_system ? __('admin.ui.view_permissions') : __('admin.ui.edit_role_and_permissions') }}</a>
            </x-management.record>
        @endforeach
        </div>

    </x-management.page>
</x-app-layout>

