@php
    // Each entry preserves the original permission and active-route conditions.
    $groups = [
        'daily' => [
            ['orders.view', 'orders.index', 'orders', ['orders.*']],
            ['customers.view', 'customers.index', 'customers', ['customers.*']],
            ['areas.view', 'areas.index', 'areas', ['areas.*']],
            ['captains.view', 'captains.index', 'captains', ['captains.*']],
            ['captain_groups.view', 'captain-groups.index', 'captain_groups', ['captain-groups.*']],
            ['shops.view', 'shops.index', 'shops', ['shops.*', 'shop-types.*']],
        ],
        'finance' => [
            ['invoices.view', 'invoices.index', 'invoices', ['invoices.*', 'orders.invoices.*']],
            ['invoice_collections.view', 'invoice-collections.index', 'collections', ['invoice-collections.index']],
            ['invoice_collections.sham_cash.view', 'invoice-collections.sham-cash', 'sham_cash', ['invoice-collections.sham-cash']],
            ['captain_financials.view', 'captain-financials.index', 'captain_financials', ['captain-financials.*']],
        ],
        'catalog' => [
            ['products.view', 'products.index', 'products', ['products.*', 'categories.*', 'units.*', 'product-groups.*', 'currencies.*']],
            ['product_prices.view', 'product-prices.index', 'pricing', ['product-prices.*', 'products.price-*', 'shops.price-history']],
            ['delivery_pricing_rules.view', 'delivery-pricing-rules.index', 'delivery_routes', ['delivery-pricing-rules.*']],
            ['delivery_pricing_overrides.approve', 'delivery-overrides.index', 'delivery_approvals', ['delivery-overrides.*']],
        ],
        'administration' => [
            ['branches.view', 'admin.branches.index', 'branches', ['admin.branches.*']],
            ['users.view', 'admin.users.index', 'users', ['admin.users.*']],
            ['roles.view', 'admin.roles.index', 'roles', ['admin.roles.*'], true],
            ['permissions.view', 'admin.permissions.index', 'permissions', ['admin.permissions.*']],
            ['system_settings.view', 'admin.pricing-settings.edit', 'pricing_settings', ['admin.pricing-settings.*']],
            ['system_settings.view', 'admin.delivery-pricing-settings.edit', 'delivery_settings', ['admin.delivery-pricing-*']],
            ['bill_payment_fees.manage', 'admin.bill-payment-fees.index', 'bill_payment_fees', ['admin.bill-payment-fees.*']],
        ],
    ];
@endphp
@foreach($groups as $group => $links)
    @php
        $visible = array_filter($links, fn ($link) => auth()->user()->can($link[0]) && (!($link[4] ?? false) || auth()->user()->isSuperAdmin()));
    @endphp
    @if($visible)
        @if($mobile)
            <div class="py-2">
                <p class="px-4 py-2 text-sm font-semibold text-brand-primary">{{ __('admin.navigation.'.$group) }}</p>
                @foreach($visible as $link)
                    <x-responsive-nav-link :href="route($link[1])" :active="request()->routeIs(...$link[3])">{{ __('admin.navigation.'.$link[2]) }}</x-responsive-nav-link>
                @endforeach
            </div>
        @else
            <x-dropdown align="left" width="64">
                <x-slot name="trigger"><button type="button" :aria-expanded="open.toString()" class="rounded-md px-2 py-2 text-sm font-medium text-gray-700 hover:text-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary">{{ __('admin.navigation.'.$group) }}</button></x-slot>
                <x-slot name="content">
                    @foreach($visible as $link)
                        <x-dropdown-link :href="route($link[1])" :aria-current="request()->routeIs(...$link[3]) ? 'page' : null">{{ __('admin.navigation.'.$link[2]) }}</x-dropdown-link>
                    @endforeach
                </x-slot>
            </x-dropdown>
        @endif
    @endif
@endforeach
