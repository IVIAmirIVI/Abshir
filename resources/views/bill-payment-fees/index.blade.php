<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Bill-payment service fees</h2></x-slot>
    <div class="max-w-6xl mx-auto px-4 py-8 space-y-5">
        @if(session('status'))<p class="rounded bg-green-50 p-4">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="rounded bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <form method="POST" action="{{ route('admin.bill-payment-fees.store') }}" class="rounded bg-white p-5 shadow flex flex-wrap gap-2 items-center">
            @csrf
            <select name="currency_id" class="rounded border-gray-300" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select>
            <x-text-input name="fixed_fee" placeholder="Fixed fee" required />
            <x-text-input name="effective_from" type="datetime-local" required />
            <x-primary-button>Create first fee rule</x-primary-button>
        </form>
        @foreach($rules as $rule)
            <article class="rounded bg-white p-5 shadow space-y-2">
                <p><strong>{{ $rule->currency->code }} · {{ $rule->fixed_fee }}</strong> · From {{ $rule->effective_from->format('Y-m-d H:i') }} · Until {{ $rule->effective_until?->format('Y-m-d H:i') ?? 'open' }}</p>
                @if(!$rule->effective_until)
                    <form method="POST" action="{{ route('admin.bill-payment-fees.version', $rule) }}" class="flex flex-wrap gap-2 items-center">
                        @csrf
                        <x-text-input name="fixed_fee" placeholder="New fixed fee" required />
                        <x-text-input name="effective_from" type="datetime-local" required />
                        <x-primary-button>Supersede with new version</x-primary-button>
                    </form>
                @endif
            </article>
        @endforeach
        {{ $rules->links() }}
    </div>
</x-app-layout>
