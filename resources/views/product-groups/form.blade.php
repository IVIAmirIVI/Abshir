<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $productGroup->exists ? 'Edit Product Group' : 'Create Product Group' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl rounded-lg bg-white p-5 shadow space-y-6 sm:p-8" method="POST" action="{{ $productGroup->exists ? route('product-groups.update', $productGroup) : route('product-groups.store') }}">@csrf @if($productGroup->exists)@method('PUT')@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $productGroup->name) }}" required /></div><div><x-input-label for="code" value="Unique code" /><x-text-input id="code" name="code" class="mt-1 block w-full" value="{{ old('code', $productGroup->code) }}" /></div></div>
        <div><x-input-label for="description" value="Description" /><textarea id="description" name="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300">{{ old('description', $productGroup->description) }}</textarea></div>
        <p class="text-sm text-gray-500">Groups support commercial targeting and reporting. They contain no pricing or commission rules.</p>
        <div class="flex gap-4"><x-primary-button>Save Product Group</x-primary-button><a class="self-center underline text-sm" href="{{ route('product-groups.index') }}">Cancel</a></div>
    </form></div>
</x-app-layout>
