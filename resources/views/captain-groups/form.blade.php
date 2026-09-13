<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $captainGroup->exists ? 'Edit Captain group' : 'Create Captain group' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $captainGroup->exists ? route('captain-groups.update', $captainGroup) : route('captain-groups.store') }}">@csrf @if($captainGroup->exists) @method('PUT') @endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $captainGroup->name) }}" required /></div><div><x-input-label for="code" value="Code" /><x-text-input id="code" name="code" class="mt-1 block w-full" value="{{ old('code', $captainGroup->code) }}" /></div></div>
        <div><x-input-label for="description" value="Description" /><textarea id="description" name="description" rows="5" class="mt-1 block w-full rounded-md border-gray-300">{{ old('description', $captainGroup->description) }}</textarea></div>
        <p class="text-sm text-gray-500">Groups classify operations and reporting. Vehicle type is separate and deferred.</p>
        <div class="flex flex-wrap gap-4"><x-primary-button>Save group</x-primary-button><a class="self-center underline text-sm" href="{{ route('captain-groups.index') }}">Cancel</a></div>
    </form></div>
</x-app-layout>
