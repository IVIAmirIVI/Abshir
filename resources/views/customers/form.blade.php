<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $customer->exists ? 'Edit customer' : 'Create customer' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
            @csrf @if($customer->exists) @method('PUT') @endif
            <div><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $customer->name) }}" required autofocus /><x-input-error :messages="$errors->get('name')" class="mt-2" /></div>
            <div><x-input-label for="notes" value="Notes" /><textarea id="notes" name="notes" rows="5" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">{{ old('notes', $customer->notes) }}</textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
            <div class="flex flex-wrap gap-4"><x-primary-button>Save customer</x-primary-button><a class="text-sm underline self-center" href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}">Cancel</a></div>
        </form>
    </div>
</x-app-layout>
