<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Create Order</h2></x-slot>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 space-y-4">
        @if($errors->any())<div class="rounded bg-red-50 p-4 text-red-800"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('orders.store') }}" class="grid gap-4 rounded bg-white p-5 shadow sm:grid-cols-2" data-order-entry-form>
            @csrf
            <div><x-input-label for="branch_id" value="Branch"/><select id="branch_id" name="branch_id" class="mt-1 w-full rounded border-gray-300" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
            <div><x-input-label for="customer_id" value="Customer"/><select id="customer_id" name="customer_id" class="mt-1 w-full rounded border-gray-300" required>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }}</option>@endforeach</select></div>
            <div><x-input-label for="customer_phone_id" value="Customer phone"/><select id="customer_phone_id" name="customer_phone_id" class="mt-1 w-full rounded border-gray-300" required>@foreach($customers as $customer)@foreach($customer->phones as $phone)<option value="{{ $phone->id }}" @selected(old('customer_phone_id') == $phone->id)>{{ $customer->name }} — {{ $phone->phone }}</option>@endforeach @endforeach</select></div>
            <div><x-input-label for="customer_address_id" value="Delivery address"/><select id="customer_address_id" name="customer_address_id" class="mt-1 w-full rounded border-gray-300" required>@foreach($customers as $customer)@foreach($customer->addresses->where('is_active', true) as $address)<option value="{{ $address->id }}" @selected(old('customer_address_id') == $address->id)>{{ $customer->name }} — {{ $address->area->branch->name }} / {{ $address->address_line_1 }}</option>@endforeach @endforeach</select></div>
            <div><x-input-label for="source" value="Order source"/><select id="source" name="source" class="mt-1 w-full rounded border-gray-300" required>@foreach($sources as $source)<option value="{{ $source->value }}" @selected(old('source') === $source->value)>{{ $source->label() }}</option>@endforeach</select></div>
            <div><x-input-label for="entry_mode" value="Entry mode"/><select id="entry_mode" name="entry_mode" class="mt-1 w-full rounded border-gray-300" required>@foreach($entryModes as $mode)<option value="{{ $mode->value }}" @selected(old('entry_mode', 'LIVE') === $mode->value)>{{ $mode->label() }}</option>@endforeach</select></div>
            <div data-historical-time class="hidden"><x-input-label for="ordered_at" value="Historical order date and time"/><x-text-input id="ordered_at" name="ordered_at" type="datetime-local" max="{{ now()->format('Y-m-d\TH:i') }}" class="mt-1 w-full" value="{{ old('ordered_at') }}"/><p class="mt-1 text-xs text-gray-600">System entry time remains the current time.</p></div>
            <div class="sm:col-span-2"><x-input-label for="notes" value="Notes"/><textarea id="notes" name="notes" class="mt-1 w-full rounded border-gray-300">{{ old('notes') }}</textarea></div>
            <x-primary-button>Create and add Shops</x-primary-button>
        </form>
    </div>
    <script>
        (() => { const mode = document.getElementById('entry_mode'); const wrapper = document.querySelector('[data-historical-time]'); const input = document.getElementById('ordered_at'); if (!mode || !wrapper || !input) return; const sync = () => { const historical = mode.value === 'HISTORICAL_MANUAL'; wrapper.classList.toggle('hidden', !historical); input.required = historical; }; mode.addEventListener('change', sync); sync(); })();
    </script>
</x-app-layout>
