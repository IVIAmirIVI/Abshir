<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $unit->exists ? __('catalog.edit_unit') : __('catalog.create_unit') }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl rounded-lg bg-white p-5 shadow space-y-6 sm:p-8" method="POST" action="{{ $unit->exists ? route('units.update', $unit) : route('units.store') }}">@csrf @if($unit->exists)@method('PUT')@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="name" :value="__('common.fields.name')" /><x-text-input id="name" name="name" class="mt-1 block w-full" value="{{ old('name', $unit->name) }}" required dir="auto" /></div><div><x-input-label for="code" :value="__('catalog.unique_code')" /><x-text-input id="code" name="code" class="mt-1 block w-full" value="{{ old('code', $unit->code) }}" required dir="ltr" /></div></div>
        <div><x-input-label for="symbol" :value="__('catalog.symbol')" /><x-text-input id="symbol" name="symbol" class="mt-1 block w-full" value="{{ old('symbol', $unit->symbol) }}" dir="ltr" /></div>
        <input type="hidden" name="allows_decimal" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="allows_decimal" value="1" @checked(old('allows_decimal', $unit->allows_decimal))> {{ __('catalog.allow_fractional_quantities') }}</label>
        <div class="flex flex-wrap gap-4"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('catalog.save_unit') }}</x-primary-button><a class="self-center underline text-sm" href="{{ route('units.index') }}">{{ __('common.actions.cancel') }}</a></div>
    </form></div>
</x-app-layout>
