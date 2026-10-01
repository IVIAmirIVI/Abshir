<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $captainGroup->exists ? __('captains.ui_edit_captain_group') : __('captains.ui_create_captain_group') }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $captainGroup->exists ? route('captain-groups.update', $captainGroup) : route('captain-groups.store') }}">@csrf @if($captainGroup->exists) @method('PUT') @endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="name" :value="__('common.fields.name')" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $captainGroup->name) }}" required dir="auto" /></div><div><x-input-label for="code" :value="__('common.fields.code')" /><x-text-input id="code" name="code" class="mt-1 block w-full" value="{{ old('code', $captainGroup->code) }}" dir="ltr" /></div></div>
        <div><x-input-label for="description" :value="__('shops.ui_description')" /><textarea id="description" name="description" rows="5" class="mt-1 block w-full rounded-md border-gray-300" dir="auto">{{ old('description', $captainGroup->description) }}</textarea></div>
        <p class="text-sm text-gray-500">{{ __('captains.ui_groups_classify_operations_and_reporting_vehicle_type_is_separate_and_deferred') }}</p>
        <div class="flex flex-wrap gap-4"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('captains.ui_save_group') }}</x-primary-button><a class="self-center underline text-sm" href="{{ route('captain-groups.index') }}">{{ __('common.actions.cancel') }}</a></div>
    </form></div>
</x-app-layout>
