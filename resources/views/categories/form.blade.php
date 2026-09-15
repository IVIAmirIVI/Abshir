<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $category->exists ? 'Edit category' : 'Create category' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl rounded-lg bg-white p-5 shadow space-y-6 sm:p-8" method="POST" action="{{ $category->exists ? route('categories.update', $category) : route('categories.store') }}">@csrf @if($category->exists)@method('PUT')@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div><x-input-label for="parent_id" value="Parent category" /><select id="parent_id" name="parent_id" class="mt-1 block w-full rounded-md border-gray-300"><option value="">No parent</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string) old('parent_id', $category->parent_id) === (string) $parent->id)>{{ $parent->path() }}</option>@endforeach</select></div>
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $category->name) }}" required /></div><div><x-input-label for="code" value="Unique code" /><x-text-input id="code" name="code" class="mt-1 block w-full" value="{{ old('code', $category->code) }}" /></div></div>
        <div><x-input-label for="sort_order" value="Sort order" /><x-text-input id="sort_order" name="sort_order" type="number" class="mt-1 block w-full" value="{{ old('sort_order', $category->sort_order ?? 0) }}" /></div>
        <div><x-input-label for="description" value="Description" /><textarea id="description" name="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300">{{ old('description', $category->description) }}</textarea></div>
        <div class="flex flex-wrap gap-4"><x-primary-button>Save category</x-primary-button><a class="self-center underline text-sm" href="{{ route('categories.index') }}">Cancel</a></div>
    </form></div>
</x-app-layout>
