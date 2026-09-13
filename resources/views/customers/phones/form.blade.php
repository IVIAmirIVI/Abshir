<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $phone->exists ? 'Edit phone' : 'Add phone' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-2xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $phone->exists ? route('customers.phones.update', [$customer, $phone]) : route('customers.phones.store', $customer) }}">@csrf @if($phone->exists) @method('PUT') @endif
        <div><x-input-label for="phone" value="Phone number" /><x-text-input id="phone" name="phone" type="tel" class="mt-1 block w-full" value="{{ old('phone', $phone->phone) }}" required /><x-input-error :messages="$errors->get('phone')" class="mt-2" /></div>
        <div><x-input-label for="label" value="Label" /><x-text-input id="label" name="label" class="mt-1 block w-full" value="{{ old('label', $phone->label) }}" placeholder="Mobile, home, work…" /></div>
        <label class="flex items-center gap-2"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary', $phone->is_primary))><span>Primary phone</span></label>
        <div class="flex gap-4"><x-primary-button>Save phone</x-primary-button><a class="self-center underline text-sm" href="{{ route('customers.show', $customer) }}">Cancel</a></div>
    </form></div>
</x-app-layout>
