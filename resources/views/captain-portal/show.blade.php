<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-bold"><x-ltr>{{ $order->order_number }}</x-ltr></h2></x-slot>
    <x-management.page>
        <div class="min-w-0 space-y-5" data-captain-portal-detail>
            <div class="flex flex-wrap gap-3"><a class="btn btn-outline" href="{{ route('captain.portal') }}">{{ __('captain_portal.title') }}</a><a class="btn btn-outline" href="{{ route('captain.self-information') }}">{{ __('captain_portal.financial_information') }}</a></div>
            <x-mary-card class="min-w-0 border">
                <h1 class="text-xl font-bold">{{ __('orders.statuses.'.$order->operational_status) }}</h1>
                <p>{{ $branch?->name }} · {{ __('captain_portal.'.($order->history_shared ? 'shared' : 'single')) }}</p>
                <p>{{ __('captain_portal.'.($order->removed_at !== null ? 'former' : ($collector ? 'collector' : 'support'))) }}</p>
                <p><x-ltr>{{ \App\Support\UiDate::datetime($order->ordered_at) }}</x-ltr></p>
                <p class="mt-3 text-sm">{{ __('captain_portal.read_only') }}</p>
            </x-mary-card>
            @if(!$work)<p class="alert">{{ __('captain_portal.history_private') }}</p>@else
                <x-mary-card :title="__('captain_portal.destination')" class="min-w-0 border">
                    <p>{{ $contact->recipient_name_snapshot ?: $contact->customer_name_snapshot }}</p>
                    <p><x-ltr>{{ $contact->customer_phone_snapshot }}</x-ltr></p>
                    <p>{{ $contact->area_name_snapshot }} · {{ $contact->address_line_1_snapshot }} {{ $contact->address_line_2_snapshot }}</p>
                    @if($contact->landmark_snapshot)<p>{{ $contact->landmark_snapshot }}</p>@endif
                </x-mary-card>
                @if($collector)<x-mary-card :title="__('captain_portal.collection')" class="min-w-0 border">
                    @if($invoice)
                        @if($invoice->collection_method)<p>{{ __('captain_portal.collection_recorded') }} · {{ __('financial.collection_methods.'.$invoice->collection_method) }}</p>@else<p>{{ __('captain_portal.invoice_uncollected') }}</p><x-money :amount="$invoice->grand_total" :currency="$invoice->currency" />@endif
                    @else<p>{{ __('captain_portal.invoice_not_issued') }}</p>@endif
                    <p class="mt-2 text-xs">{{ __('captain_portal.no_collection_action') }}</p>
                </x-mary-card>@endif
                <h2 class="text-xl font-bold">{{ __('captain_portal.shops') }}</h2>
                <div class="grid gap-4 lg:grid-cols-2">
                    @forelse($shops as $shop)<x-mary-card class="min-w-0 border">
                        <h3 class="text-lg font-bold">{{ $shop->shop_name_snapshot }}</h3>
                        <p>{{ $shop->area_name_snapshot }} {{ $shop->shop_address_snapshot }}</p><p><x-ltr>{{ $shop->shop_phone_snapshot }}</x-ltr></p>
                        <dl class="mt-3 space-y-2">@foreach(['contacted_at','confirmed_at','ready_at','purchased_at','picked_up_at'] as $milestone)<div class="flex flex-wrap justify-between gap-2"><dt>{{ __('captain_portal.milestones.'.$milestone) }}</dt><dd><x-ltr>{{ \App\Support\UiDate::datetime($shop->{$milestone}) }}</x-ltr></dd></div>@endforeach</dl>
                        <p class="mt-2">{{ __('captain_portal.receipt') }}: {{ __('captain_portal.'.($shop->receipt_count > 0 ? 'recorded' : 'missing')) }}</p>
                        <ul class="mt-3 space-y-2">@foreach($shop->items as $item)<li class="rounded-lg bg-stone-50 p-3">{{ $item->product_name_snapshot }} · <x-ltr>{{ \App\Support\UiDecimal::format($item->current_quantity) }}</x-ltr> {{ $item->unit_name_snapshot }}</li>@endforeach</ul>
                    </x-mary-card>@empty<p>{{ __('captain_portal.no_shops') }}</p>@endforelse
                </div>
            @endif
            <x-mary-card :title="__('captain_portal.history_status')" class="min-w-0 border">
                <p class="text-xs">{{ __('captain_portal.history_limit') }}</p>
                <ul class="mt-3 space-y-3">@forelse($history as $transition)<li class="flex flex-wrap justify-between gap-2"><span>{{ __('orders.statuses.'.$transition->new_status) }}</span><x-ltr>{{ \App\Support\UiDate::datetime($transition->changed_at) }}</x-ltr></li>@empty<li>{{ __('captain_portal.no_history') }}</li>@endforelse</ul>
            </x-mary-card>
        </div>
    </x-management.page>
</x-app-layout>
