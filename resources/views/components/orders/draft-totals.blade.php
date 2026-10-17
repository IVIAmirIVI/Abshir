@props(['shop' => false])
<span class="block text-xs leading-5 text-gray-600" data-draft-total-unavailable>{{ __('order_ux.'.($shop ? 'shop_estimate' : 'line_estimate')) }}: {{ __('common.operational_unavailable') }} · {{ __('order_ux.'.($shop ? 'shop_actual' : 'line_actual')) }}: {{ __('common.operational_unavailable') }}</span>
