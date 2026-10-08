<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $branch->exists ? __('admin.ui.edit_branch') : __('admin.ui.create_branch') }}</h2></x-slot>
    <x-management.page>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <x-orders.section id="management-admin-branches"><form class="max-w-3xl space-y-6" method="POST" action="{{ $branch->exists ? route('admin.branches.update', $branch) : route('admin.branches.store') }}">
            @csrf @if($branch->exists) @method('PUT') @endif
            <div class="grid gap-5 sm:grid-cols-2"><div class="min-w-0"><x-input-label for="name" :value="__('admin.ui.name')" /><x-text-input id="name" name="name" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" type="text" class="mt-1 block w-full" value="{{ old('name', $branch->name) }}" required /></div><div class="min-w-0"><x-input-label for="code" :value="__('admin.ui.unique_code')" /><x-text-input id="code" dir="ltr" name="code" aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}" type="text" class="mt-1 block w-full" value="{{ old('code', $branch->code) }}" required /></div></div>
            <p class="text-sm text-gray-500">{{ __('admin.ui.use_uppercase_letters_numbers_hyphens_or_underscores_for_the_code') }}</p>
            <div class="min-w-0"><x-input-label for="phone" :value="__('admin.ui.phone')" /><x-text-input id="phone" dir="ltr" name="phone" aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}" type="tel" inputmode="numeric" class="mt-1 block w-full" value="{{ old('phone', $branch->phone) }}"  /></div>
            <div><x-input-label for="default_currency_id" :value="__('admin.ui.default_currency')" /><select id="default_currency_id" name="default_currency_id" aria-invalid="{{ $errors->has('default_currency_id') ? 'true' : 'false' }}" class="mt-1 block w-full rounded-md border-gray-300"><option value="">{{ __('admin.ui.no_default_currency') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) old('default_currency_id', $branch->default_currency_id) === (string) $currency->id)>{{ $currency->code }} · {{ $currency->name }}</option>@endforeach</select></div>
            <div><x-input-label for="address" :value="__('admin.ui.address')" /><textarea id="address" name="address" aria-invalid="{{ $errors->has('address') ? 'true' : 'false' }}" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">{{ old('address', $branch->address) }}</textarea></div>
            <div class="flex flex-wrap items-center gap-4"><x-orders.action>{{ __('admin.ui.save_branch') }}</x-orders.action><a class="text-sm underline" href="{{ route('admin.branches.index') }}">{{ __('admin.ui.cancel') }}</a></div>
        </form></x-orders.section>

    </x-management.page>
</x-app-layout>

