<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

class ApplicationNavigation
{
    private static function catalog(): array
    {
        return [
            'daily' => [
                [CaptainSelfView::ABILITY, 'captain.self-information', 'my_captain_information', ['captain.self-information']],
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
                [StaffInbox::ABILITY, 'notifications.index', 'notifications', ['notifications.*']],
                ['manage-authorization', 'admin.security-audit.index', 'security_audit', ['admin.security-audit.*']],
                ['branches.view', 'admin.branches.index', 'branches', ['admin.branches.*']],
                ['users.view', 'admin.users.index', 'users', ['admin.users.*']],
                ['roles.view', 'admin.roles.index', 'roles', ['admin.roles.*'], true],
                ['permissions.view', 'admin.permissions.index', 'permissions', ['admin.permissions.*']],
                ['system_settings.view', 'admin.pricing-settings.edit', 'pricing_settings', ['admin.pricing-settings.*']],
                ['system_settings.view', 'admin.delivery-pricing-settings.edit', 'delivery_settings', ['admin.delivery-pricing-*']],
                ['bill_payment_fees.manage', 'admin.bill-payment-fees.index', 'bill_payment_fees', ['admin.bill-payment-fees.*']],
            ],
        ];
    }

    public static function groups(User $user): array
    {
        $groups = [];
        $bestMatch = null;
        $specificity = -1;
        foreach (self::catalog() as $group => $links) {
            $items = [];
            foreach ($links as $link) {
                if (! Route::has($link[1]) || ! $user->can($link[0]) || (($link[4] ?? false) && ! $user->isSuperAdmin())) {
                    continue;
                }
                foreach ($link[3] as $pattern) {
                    $length = strlen(str_replace('*', '', $pattern));
                    if (request()->routeIs($pattern) && $length > $specificity) {
                        $bestMatch = $link[1];
                        $specificity = $length;
                    }
                }
                $items[] = ['route' => $link[1], 'label' => __('admin.navigation.'.$link[2])];
            }
            if ($items) {
                $groups[] = ['key' => $group, 'label' => __('admin.navigation.'.$group), 'items' => $items];
            }
        }
        foreach ($groups as &$group) {
            foreach ($group['items'] as &$item) {
                $item['active'] = $item['route'] === $bestMatch;
            }
        }

        return $groups;
    }
}
