<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('admin.ui.permissions') }}</h2></x-slot>
    <x-management.page>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <p class="text-gray-600">{{ __('admin.ui.the_available_permission_catalog_role_and_branch_assignment_is_reserved_for_super_administrators') }}</p>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($permissions as $permission)<x-management.record><h3 class="font-medium break-all">{{ \App\Support\UiLabels::permission($permission->code, $permission->name) }}</h3><p class="mt-1 text-sm text-gray-600"><x-ltr>{{ $permission->code }}</x-ltr></p></x-management.record>@endforeach</div>

    </x-management.page>
</x-app-layout>

