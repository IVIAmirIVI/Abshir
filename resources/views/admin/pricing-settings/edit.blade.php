<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('admin.ui.product_pricing_settings') }}</h2></x-slot>
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-5">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('admin.pricing-settings.update') }}" class="rounded-lg bg-white p-5 shadow space-y-5">@csrf @method('PUT')<div><x-input-label for="estimation_window_days" :value="__('admin.ui.recent_price_window_days')" /><x-text-input id="estimation_window_days" name="estimation_window_days" type="number" min="1" max="365" class="mt-1 block w-full" :value="old('estimation_window_days', $windowDays)" required /><p class="mt-2 text-sm text-gray-600">{{ __('admin.ui.unknown_shop_estimates_include_observations_from_this_many_days_before_the_reference_time_through_the_reference_time') }}</p></div><x-primary-button>{{ __('admin.ui.save_setting') }}</x-primary-button></form>
    </div>
</x-app-layout>
