<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('admin.ui.delivery_pricing_settings') }}</h2></x-slot>
    <x-management.page>
        @if(session('status'))<p role="status" class="rounded bg-green-50 p-4">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('admin.delivery-pricing-settings.update') }}" class="grid gap-4 rounded bg-white p-5 shadow sm:grid-cols-2">
            @csrf @method('PUT')
            <div><x-input-label :required="true" for="captain_share_percent" :value="__('admin.ui.captain_delivery_share')"/>
                <x-text-input class="mt-1 block w-full" dir="ltr" id="captain_share_percent" name="captain_share_percent" type="number" step="0.0001" min="0" max="100" :value="old('captain_share_percent', $share)" required aria-describedby="{{ $errors->has('captain_share_percent') ? 'captain_share_percent-error' : '' }}" aria-invalid="{{ $errors->has('captain_share_percent') ? 'true' : 'false' }}"/>
                <x-input-error id="captain_share_percent-error" :messages="$errors->get('captain_share_percent')"/>
            </div>
            <div><x-input-label :required="true" for="override_threshold_percent" :value="__('admin.ui.employee_override_threshold')"/>
                <x-text-input class="mt-1 block w-full" dir="ltr" id="override_threshold_percent" name="override_threshold_percent" type="number" step="0.0001" min="0" max="100" :value="old('override_threshold_percent', $threshold)" required aria-describedby="{{ $errors->has('override_threshold_percent') ? 'override_threshold_percent-error' : '' }}" aria-invalid="{{ $errors->has('override_threshold_percent') ? 'true' : 'false' }}"/>
                <x-input-error id="override_threshold_percent-error" :messages="$errors->get('override_threshold_percent')"/>
            </div>
            <div class="sm:col-span-2"><x-primary-button>{{ __('admin.ui.save_settings') }}</x-primary-button></div>
        </form>
        @can('delivery_discount_limits.manage')
            <section class="rounded bg-white p-5 shadow">
                <h3 class="mb-4 font-semibold">{{ __('admin.ui.employee_delivery_discount_limits') }}</h3>
                <form method="POST" action="{{ route('admin.delivery-pricing-user-limits.update') }}" class="grid gap-3 sm:grid-cols-2">
                    @csrf @method('PUT')
                    <div><x-input-label for="discount-user" :value="__('admin.ui.users')"/>
                        <select id="discount-user" name="user_id" class="mt-1 block w-full rounded border-gray-300" aria-describedby="{{ $errors->has('user_id') ? 'discount-user-error' : '' }}" aria-invalid="{{ $errors->has('user_id') ? 'true' : 'false' }}">@foreach($employees as $u)<option value="{{ $u->id }}" @selected((string) old('user_id') === (string) $u->id)>{{ $u->name }}</option>@endforeach</select>
                        <x-input-error id="discount-user-error" :messages="$errors->get('user_id')"/>
                    </div>
                    <div><x-input-label for="max_discount_percent" :value="__('admin.ui.maximum')"/>
                        <x-text-input class="mt-1 block w-full" dir="ltr" id="max_discount_percent" name="max_discount_percent" type="number" step="0.0001" min="0" max="100" :value="old('max_discount_percent')" :placeholder="__('admin.ui.maximum')" aria-describedby="{{ $errors->has('max_discount_percent') ? 'max_discount_percent-error' : '' }}" aria-invalid="{{ $errors->has('max_discount_percent') ? 'true' : 'false' }}"/>
                        <x-input-error id="max_discount_percent-error" :messages="$errors->get('max_discount_percent')"/>
                    </div>
                    <div class="sm:col-span-2"><x-primary-button>{{ __('admin.ui.save_limit') }}</x-primary-button></div>
                </form>
                <div class="mt-4 grid gap-2 sm:grid-cols-2">@foreach($limits as $limit)<p>{{ $limit->user->name }}: <x-ltr>{{ $limit->max_discount_percent }}%</x-ltr></p>@endforeach</div>
            </section>
        @endcan
    </x-management.page>
</x-app-layout>
