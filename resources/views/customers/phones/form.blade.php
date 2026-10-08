<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $phone->exists ? __('customers.edit_phone') : __('customers.add_phone') }}</h2></x-slot>
    <x-management.page><x-orders.section id="management-customers-phones"><form class="max-w-2xl space-y-6" method="POST" action="{{ $phone->exists ? route('customers.phones.update', [$customer, $phone]) : route('customers.phones.store', $customer) }}" data-phone-match-url="{{ route('customers.phone-matches', $customer) }}">@csrf @if($phone->exists) @method('PUT') @endif
        <div><x-input-label for="phone" :value="__('common.fields.phone')" /><x-text-input id="phone" name="phone" aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}" type="tel" inputmode="numeric" x-data="{ mobileValue: $el.value }" x-model="mobileValue" x-bind:pattern="mobileValue.replace(/[^0-9]/g, '').startsWith('09') ? '09[0-9]{8}' : null" class="mt-1 block w-full" value="{{ old('phone', $phone->phone) }}" required dir="ltr" /><x-input-error :messages="$errors->get('phone')" class="mt-2" /><p class="mt-1 text-sm text-gray-600">{{ __('order_ux.phone_help') }}</p></div>
        <div data-phone-warning class="hidden rounded bg-amber-50 p-4 text-amber-900"><p class="font-medium">{{ __('customers.phone_matches') }}</p><div data-phone-matches class="mt-2 space-y-1 text-sm"></div><label class="mt-3 flex items-start gap-2"><input type="checkbox" name="duplicate_acknowledged" aria-invalid="{{ $errors->has('duplicate_acknowledged') ? 'true' : 'false' }}" value="1" @checked(old('duplicate_acknowledged'))><span>{{ __('customers.acknowledge_matches') }}</span></label><x-input-error :messages="$errors->get('duplicate_acknowledged')" class="mt-2" /></div>
        <div><x-input-label for="label" :value="__('common.fields.label')" /><x-text-input id="label" name="label" aria-invalid="{{ $errors->has('label') ? 'true' : 'false' }}" class="mt-1 block w-full" value="{{ old('label', $phone->label) }}" placeholder="{{ __('customers.phone_labels') }}" dir="auto" /></div>
        <label class="flex items-center gap-2"><input type="checkbox" name="is_primary" aria-invalid="{{ $errors->has('is_primary') ? 'true' : 'false' }}" value="1" @checked(old('is_primary', $phone->is_primary))><span>{{ __('customers.primary_phone') }}</span></label>
        <div class="flex flex-wrap gap-4"><x-orders.action>{{ __('customers.save_phone') }}</x-orders.action><a class="self-center underline text-sm" href="{{ route('customers.show', $customer) }}">{{ __('common.actions.cancel') }}</a></div>
    </form></x-orders.section></x-management.page>
    <script>
        (() => {
            const form = document.querySelector('[data-phone-match-url]');
            const phone = document.getElementById('phone');
            const warning = document.querySelector('[data-phone-warning]');
            const matches = document.querySelector('[data-phone-matches]');
            if (!form || !phone || !warning || !matches) return;
            const matchPrefix = {{ \Illuminate\Support\Js::from(__('customers.match_prefix')) }};
            let requestNumber = 0;
            const check = async () => {
                const current = ++requestNumber;
                matches.replaceChildren();
                if (!phone.value.trim()) { warning.classList.add('hidden'); return; }
                const url = new URL(form.dataset.phoneMatchUrl, window.location.origin);
                url.searchParams.set('phone', phone.value);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok || current !== requestNumber) return;
                const data = await response.json();
                warning.classList.toggle('hidden', data.matches.length === 0);
                data.matches.forEach((match) => {
                    const row = document.createElement('p');
                    const link = document.createElement('a');
                    link.className = 'underline';
                    link.href = match.url;
                    link.textContent = match.name;
                    row.append(matchPrefix, link);
                    matches.append(row);
                });
            };
            phone.addEventListener('blur', check);
            phone.addEventListener('change', check);
            if (phone.value) check();
        })();
    </script>
</x-app-layout>
