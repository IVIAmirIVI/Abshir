@php($addonSources = app(\App\Services\SpecialProductDeliveryService::class)->orderSources($order))
@if($addonSources && $order->shops->count() > 1 && \Illuminate\Support\Facades\Schema::hasTable('order_special_addon_approvals'))
    <section class="space-y-3 rounded-lg border border-amber-200 p-3">
        <h4 class="font-semibold">{{ __('special.addon_title') }}</h4><p class="text-sm">{{ __('special.addon_help') }}</p>
        @foreach($order->shops as $addonShop)
            @if(!in_array($addonShop->id, array_column($addonSources, 'shop_key')))
                <details class="rounded-lg border p-3"><summary class="cursor-pointer">{{ $addonShop->shop_name_snapshot }}</summary>
                    <p class="mt-2 text-sm">{{ $addonShop->items->pluck('product_name_snapshot')->implode('، ') }}</p>
                    @php($currentAddonProof = \Illuminate\Support\Facades\DB::table('order_special_addon_approvals')->where('order_shop_id', $addonShop->id)->whereRaw('facts=special_addon_facts(order_shop_id,source_order_item_id)')->latest('id')->first())
                    @if($currentAddonProof)<p class="mt-2 text-sm text-green-800">{{ __('special.addon_approved') }}</p>@endif
                    @foreach(\Illuminate\Support\Facades\DB::table('order_special_addon_approvals')->where('order_shop_id', $addonShop->id)->orderByDesc('id')->limit(5)->get() as $approval)
                        <p class="mt-2 break-words text-sm">{{ __('special.addon_history') }} · {{ \App\Models\User::find($approval->approved_by)?->name }} · <x-ltr>{{ \App\Support\UiDate::datetime($approval->approved_at) }}</x-ltr> · {{ $approval->reason }}</p>
                    @endforeach
                    @if(!$currentAddonProof && !app(\App\Services\OrderMutationGuard::class)->isPricingFrozen($order))@can('orders.update')@can('delivery_pricing.apply')
                        <form method="POST" action="{{ route('orders.special-addons', $order) }}" class="mt-3 space-y-3">@csrf
                            <input type="hidden" name="order_shop_id" value="{{ $addonShop->id }}">
                            <label class="block text-sm">{{ __('special.addon_source') }}<select name="source_order_item_id" class="mt-1 w-full rounded-lg border-gray-300" required>@foreach($addonSources as $source)<option value="{{ $source['item_id'] }}">{{ $source['label'] }}</option>@endforeach</select></label>
                            <label class="block text-sm">{{ __('special.addon_reason') }}<textarea name="reason" maxlength="1000" class="mt-1 w-full rounded-lg border-gray-300" required></textarea></label>
                            <x-orders.action>{{ __('special.addon_approve') }}</x-orders.action>
                        </form>
                    @endcan @endcan @endif
                </details>
            @endif
        @endforeach
    </section>
@endif
