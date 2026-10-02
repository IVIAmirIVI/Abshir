<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">{{ __('admin.ui.bill_payment_service_fees') }}</h2></x-slot>
    <div class="max-w-6xl mx-auto px-4 py-8 space-y-5">
        @if(session('status'))<p class="rounded bg-green-50 p-4">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="rounded bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <form method="POST" action="{{ route('admin.bill-payment-fees.store') }}" class="rounded bg-white p-5 shadow flex flex-wrap gap-2 items-center">
            @csrf
            <select aria-label="{{ __('admin.ui.currency') }}" name="currency_id" class="min-w-0 max-w-full rounded border-gray-300" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select>
            <x-text-input class="min-w-0 max-w-full" dir="ltr" aria-label="{{ __('admin.ui.fixed_fee') }}" name="fixed_fee" :placeholder="__('admin.ui.fixed_fee')" required />
            <x-text-input class="min-w-0 max-w-full" dir="ltr" aria-label="{{ __('admin.ui.effective_from') }}" name="effective_from" type="datetime-local" required />
            <x-primary-button>{{ __('admin.ui.create_first_fee_rule') }}</x-primary-button>
        </form>
        @forelse($rules as $rule)
            <article class="rounded bg-white p-5 shadow space-y-2">
                <p><strong><x-money :amount="$rule->fixed_fee" :currency="$rule->currency->code" /></strong> · {{ __('admin.from') }} <x-ltr>{{ $rule->effective_from->format('Y-m-d H:i') }}</x-ltr> · {{ __('admin.until') }} @if($rule->effective_until)<x-ltr>{{ $rule->effective_until->format('Y-m-d H:i') }}</x-ltr>@else{{ __('admin.ui.open') }}@endif</p>
                @if(!$rule->effective_until)
                    <form method="POST" action="{{ route('admin.bill-payment-fees.version', $rule) }}" class="flex flex-wrap gap-2 items-center">
                        @csrf
                        <x-text-input class="min-w-0 max-w-full" dir="ltr" name="fixed_fee" :placeholder="__('admin.ui.new_fixed_fee')" required />
                        <x-text-input class="min-w-0 max-w-full" dir="ltr" name="effective_from" type="datetime-local" required />
                        <x-primary-button>{{ __('admin.ui.supersede_with_new_version') }}</x-primary-button>
                    </form>
                @endif
            </article>
        @empty<p>{{ __('admin.no_fees') }}</p>@endforelse
        {{ $rules->links() }}
    </div>
</x-app-layout>
