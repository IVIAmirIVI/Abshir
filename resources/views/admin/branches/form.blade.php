<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $branch->exists ? 'Edit branch' : 'Create branch' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $branch->exists ? route('admin.branches.update', $branch) : route('admin.branches.store') }}">
            @csrf @if($branch->exists) @method('PUT') @endif
            <div class="grid gap-5 sm:grid-cols-2"><div class="min-w-0"><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $branch->name) }}" required /></div><div class="min-w-0"><x-input-label for="code" value="Unique code" /><x-text-input id="code" name="code" type="text" class="mt-1 block w-full" value="{{ old('code', $branch->code) }}" required /></div></div>
            <p class="text-sm text-gray-500">Use uppercase letters, numbers, hyphens or underscores for the code.</p>
            <div class="min-w-0"><x-input-label for="phone" value="Phone" /><x-text-input id="phone" name="phone" type="tel" class="mt-1 block w-full" value="{{ old('phone', $branch->phone) }}"  /></div>
            <div><x-input-label for="address" value="Address" /><textarea id="address" name="address" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">{{ old('address', $branch->address) }}</textarea></div>
            <div class="flex flex-wrap items-center gap-4"><x-primary-button>Save branch</x-primary-button><a class="text-sm underline" href="{{ route('admin.branches.index') }}">Cancel</a></div>
        </form>

    </div>
</x-app-layout>

