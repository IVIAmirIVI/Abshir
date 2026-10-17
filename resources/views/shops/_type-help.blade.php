@if($types->where('is_active', true)->isEmpty())
    <p class="mt-2 text-sm text-amber-900">{{ __('shops.ui_no_active_types') }}</p>
    @can('shop_types.create')<a class="mt-1 inline-block text-sm underline" href="{{ route('shop-types.create') }}" target="_blank" rel="noopener">{{ __('shops.ui_manage_types') }}</a>@endcan
@endif
