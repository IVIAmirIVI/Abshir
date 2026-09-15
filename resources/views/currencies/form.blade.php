<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $currency->exists ? 'Edit Currency' : 'Create Currency' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl rounded-lg bg-white p-5 shadow space-y-6 sm:p-8" method="POST" action="{{ $currency->exists ? route('currencies.update', $currency) : route('currencies.store') }}">@csrf @if($currency->exists)@method('PUT')@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="code" value="ISO code" /><x-text-input id="code" name="code" class="mt-1 block w-full" value="{{ old('code', $currency->code) }}" maxlength="3" required /></div><div><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $currency->name) }}" required /></div></div>
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="symbol" value="Symbol" /><x-text-input id="symbol" name="symbol" class="mt-1 block w-full" value="{{ old('symbol', $currency->symbol) }}" /></div><div><x-input-label for="decimal_places" value="Decimal places" /><x-text-input id="decimal_places" name="decimal_places" type="number" min="0" max="4" class="mt-1 block w-full" value="{{ old('decimal_places', $currency->decimal_places ?? 2) }}" required /></div></div>
        <div class="flex gap-4"><x-primary-button>Save Currency</x-primary-button><a class="self-center underline text-sm" href="{{ route('currencies.index') }}">Cancel</a></div>
    </form></div>
</x-app-layout>
