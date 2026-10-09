<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $user->exists ? __('admin.ui.edit_user') : __('admin.ui.create_user') }}</h2></x-slot>
    <x-management.page>
        <nav aria-label="{{ __('common.breadcrumbs') }}"><a class="underline text-sm" href="{{ route('admin.users.index') }}">{{ __('admin.ui.users') }}</a></nav>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <x-orders.section id="management-admin-users"><form class="max-w-3xl space-y-6" method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
            @csrf @if($user->exists) @method('PUT') @endif
            <div class="grid gap-5 sm:grid-cols-2"><div class="min-w-0"><x-input-label :required="true" for="name" :value="__('admin.ui.name')" /><x-text-input aria-describedby="{{ $errors->has('name') ? 'name-error' : '' }}" id="name" name="name" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" type="text" class="mt-1 block w-full" value="{{ old('name', $user->name) }}" required /><x-input-error id="name-error" class="mt-2" :messages="$errors->get('name')" /></div><div class="min-w-0"><x-input-label :required="true" for="email" :value="__('admin.ui.email')" /><x-text-input aria-describedby="{{ $errors->has('email') ? 'email-error' : '' }}" id="email" dir="ltr" name="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" type="email" class="mt-1 block w-full" value="{{ old('email', $user->email) }}" required /><x-input-error id="email-error" class="mt-2" :messages="$errors->get('email')" /></div></div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div><x-input-label :required="! $user->exists" for="password" :value="__('admin.ui.password')" /><x-text-input aria-describedby="{{ $errors->has('password') ? 'password-error' : '' }}" id="password" name="password" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" type="password" autocomplete="new-password" class="mt-1 block w-full" :required="! $user->exists" /><x-input-error id="password-error" class="mt-2" :messages="$errors->get('password')" /><p class="mt-1 text-sm text-gray-500">{{ $user->exists ? __('admin.ui.leave_blank_to_keep_the_current_password') : __('admin.ui.at_least_8_characters') }}</p></div>
                <div><x-input-label for="password_confirmation" :value="__('admin.ui.confirm_password')" /><x-text-input aria-describedby="{{ $errors->has('password_confirmation') ? 'password_confirmation-error' : '' }}" id="password_confirmation" name="password_confirmation" aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}" type="password" autocomplete="new-password" class="mt-1 block w-full" /><x-input-error id="password_confirmation-error" class="mt-2" :messages="$errors->get('password_confirmation')" /></div>
            </div>
            @if(auth()->user()->isSuperAdmin())
            <fieldset class="space-y-3"><legend class="font-semibold">{{ __('admin.ui.assigned_roles') }}</legend>
            @foreach($roles as $role)
                <label class="flex items-start gap-3 break-all"><input class="mt-1 rounded" type="checkbox" name="role_ids[]" value="{{ $role->id }}" @checked(in_array($role->id, old('role_ids', $user->roles->modelKeys())))><span>{{ \App\Support\UiLabels::role($role->code, $role->name) }} <span class="text-xs text-gray-500">(<x-ltr>{{ $role->code }}</x-ltr>)</span></span></label>
            @endforeach
            </fieldset>
            <fieldset class="space-y-3"><legend class="font-semibold">{{ __('admin.ui.assigned_branches') }}</legend><p class="text-sm text-gray-500">{{ __('admin.ui.select_any_number_of_branches_super_administrators_automatically_access_every_branch_inactive_branches_remain_assigned_but_are_unavailable_to_ordinary_users') }}</p>
            @forelse($branches as $branch)
                <label class="flex items-start gap-3 break-words"><input class="mt-1 rounded" type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array($branch->id, old('branch_ids', $user->branches->modelKeys())))><span>{{ $branch->name }} ({{ $branch->code }}){{ $branch->is_active ? '' : ' — '.__('admin.ui.inactive') }}</span></label>
            @empty<p class="text-sm">{{ __('admin.ui.create_a_branch_to_assign_access') }}</p>@endforelse
            </fieldset>
            @endif
            <div class="flex flex-wrap items-center gap-4"><x-orders.action>{{ __('admin.ui.save_user') }}</x-orders.action><a class="text-sm underline" href="{{ route('admin.users.index') }}">{{ __('admin.ui.cancel') }}</a></div>
        </form></x-orders.section>

    </x-management.page>
</x-app-layout>

