<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $phone->exists ? 'Edit phone' : 'Add phone' }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-2xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ $phone->exists ? route('customers.phones.update', [$customer, $phone]) : route('customers.phones.store', $customer) }}" data-phone-match-url="{{ route('customers.phone-matches', $customer) }}">@csrf @if($phone->exists) @method('PUT') @endif
        <div><x-input-label for="phone" value="Phone number" /><x-text-input id="phone" name="phone" type="tel" class="mt-1 block w-full" value="{{ old('phone', $phone->phone) }}" required /><x-input-error :messages="$errors->get('phone')" class="mt-2" /></div>
        <div data-phone-warning class="hidden rounded bg-amber-50 p-4 text-amber-900"><p class="font-medium">This normalized phone appears on another customer you can access.</p><div data-phone-matches class="mt-2 space-y-1 text-sm"></div><label class="mt-3 flex items-start gap-2"><input type="checkbox" name="duplicate_acknowledged" value="1" @checked(old('duplicate_acknowledged'))><span>I reviewed these possible matches and still want to save this phone.</span></label><x-input-error :messages="$errors->get('duplicate_acknowledged')" class="mt-2" /></div>
        <div><x-input-label for="label" value="Label" /><x-text-input id="label" name="label" class="mt-1 block w-full" value="{{ old('label', $phone->label) }}" placeholder="Mobile, home, work…" /></div>
        <label class="flex items-center gap-2"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary', $phone->is_primary))><span>Primary phone</span></label>
        <div class="flex gap-4"><x-primary-button>Save phone</x-primary-button><a class="self-center underline text-sm" href="{{ route('customers.show', $customer) }}">Cancel</a></div>
    </form></div>
    <script>
        (() => {
            const form = document.querySelector('[data-phone-match-url]');
            const phone = document.getElementById('phone');
            const warning = document.querySelector('[data-phone-warning]');
            const matches = document.querySelector('[data-phone-matches]');
            if (!form || !phone || !warning || !matches) return;
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
                    row.append('Existing customer: ', link);
                    matches.append(row);
                });
            };
            phone.addEventListener('blur', check);
            phone.addEventListener('change', check);
            if (phone.value) check();
        })();
    </script>
</x-app-layout>
