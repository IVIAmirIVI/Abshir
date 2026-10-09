<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $role->exists ? ($role->is_system ? __('admin.ui.view_system_role') : __('admin.ui.edit_role')) : __('admin.ui.create_role') }}</h2></x-slot>
    <x-management.page>
        <nav aria-label="{{ __('common.breadcrumbs') }}"><a class="underline text-sm" href="{{ route('admin.roles.index') }}">{{ __('admin.ui.roles') }}</a></nav>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        @if($role->is_system)<p class="rounded-lg bg-blue-50 p-4 text-blue-800">{{ __('admin.ui.this_core_role_is_protected') }} {{ $role->code === 'SUPER_ADMIN' ? __('admin.ui.super_administrators_automatically_pass_all_authorization_checks') : __('admin.ui.create_a_custom_role_to_change_access') }}</p>@endif
        <x-orders.section id="management-admin-roles"><form class="max-w-4xl space-y-6" method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}">
            @csrf @if($role->exists) @method('PUT') @endif
            <fieldset @disabled($role->is_system) class="space-y-6 min-w-0">
                <div class="grid gap-5 sm:grid-cols-2"><div class="min-w-0"><x-input-label :required="true" for="name" :value="__('admin.ui.name')" /><x-text-input aria-describedby="{{ $errors->has('name') ? 'name-error' : '' }}" id="name" name="name" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" type="text" class="mt-1 block w-full" value="{{ old('name', $role->name) }}" required /><x-input-error id="name-error" class="mt-2" :messages="$errors->get('name')" /></div><div class="min-w-0"><x-input-label :required="true" for="code" :value="__('admin.ui.role_code')" /><x-text-input aria-describedby="{{ $errors->has('code') ? 'code-error' : '' }}" id="code" dir="ltr" name="code" aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}" type="text" class="mt-1 block w-full" value="{{ old('code', $role->code) }}" required /><x-input-error id="code-error" class="mt-2" :messages="$errors->get('code')" /></div></div>
                <p class="text-sm text-gray-500">{{ __('admin.ui.use_a_stable_code_such_as_branch_manager_permission_changes_affect_every_user_assigned_to_this_role') }}</p>
                <div class="grid gap-6 sm:grid-cols-2">
                @foreach($permissions as $group => $items)
                    <fieldset class="min-w-0 space-y-3 rounded-md border p-4"><legend class="px-1 font-semibold">{{ __('admin.permission_groups.'.$group) }}</legend>
                    @foreach($items as $permission)
                        <label class="flex gap-3 items-start"><input class="mt-1 rounded" type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->code === 'SUPER_ADMIN' || in_array($permission->id, old('permission_ids', $role->permissions->modelKeys())))><span class="text-sm break-all">{{ \App\Support\UiLabels::permission($permission->code, $permission->name) }} <x-ltr class="block text-xs text-gray-500">{{ $permission->code }}</x-ltr></span></label>
                    @endforeach
                    </fieldset>
                @endforeach
                </div>
                @unless($role->is_system)<div class="flex flex-wrap items-center gap-4"><x-orders.action>{{ __('admin.ui.save_role') }}</x-orders.action><a class="text-sm underline" href="{{ route('admin.roles.index') }}">{{ __('admin.ui.cancel') }}</a></div>@endunless
            </fieldset>
            @if($role->is_system)<a class="inline-block underline text-sm" href="{{ route('admin.roles.index') }}">{{ __('admin.ui.back_to_roles') }}</a>@endif
        </form></x-orders.section>

    </x-management.page>
</x-app-layout>

