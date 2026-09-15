<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $unit->exists ? 'Edit Unit' : 'Create Unit' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl rounded-lg bg-white p-5 shadow space-y-6 sm:p-8" method="POST" action="{{ $unit->exists ? route('units.update', $unit) : route('units.store') }}">@csrf @if($unit->exists)@method('PUT')@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="name" value="Name" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $unit->name) }}" required /></div><div><x-input-label for="code" value="Unique code" /><x-text-input id="code" name="code" class="mt-1 block w-full" value="{{ old('code', $unit->code) }}" required /></div></div>
        <div><x-input-label for="symbol" value="Symbol" /><x-text-input id="symbol" name="symbol" class="mt-1 block w-full" value="{{ old('symbol', $unit->symbol) }}" /></div>
        <input type="hidden" name="allows_decimal" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="allows_decimal" value="1" @checked(old('allows_decimal', $unit->allows_decimal))> Allow fractional quantities</label>
        <div class="flex gap-4"><x-primary-button>Save Unit</x-primary-button><a class="self-center underline text-sm" href="{{ route('units.index') }}">Cancel</a></div>
    </form></div>
</x-app-layout>
