<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $groups = [
                'users' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'roles' => ['view', 'create', 'update', 'assign'],
                'permissions' => ['view'],
                'branches' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'customers' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'areas' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'captains' => ['view', 'create', 'update', 'status.update'],
                'captain_branches' => ['view', 'assign', 'transfer'],
                'captain_groups' => ['view', 'create', 'update', 'activate', 'deactivate', 'assign'],
                'currencies' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'categories' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'units' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'products' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'product_groups' => ['view', 'create', 'update', 'activate', 'deactivate', 'assign'],
                'shop_types' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'shops' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'shop_products' => ['view', 'create', 'update'],
                'shop_partnerships' => ['view', 'create', 'update'],
                'product_prices' => ['view', 'create', 'reports', 'estimate'],
                'orders' => ['view', 'create', 'update', 'status.update', 'pricing.update', 'captains.assign', 'captains.remove', 'items.change'],
                'delivery_pricing' => ['view', 'apply', 'override'],
                'delivery_pricing_rules' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'delivery_pricing_overrides' => ['approve'],
                'delivery_discounts' => ['apply'],
                'delivery_discount_limits' => ['manage'],
                'delivery_financial_details' => ['view'],
                'system_settings' => ['view', 'update'],
                'company_financials' => ['view'],
            ];
            foreach ($groups as $group => $actions) {
                foreach ($actions as $action) {
                    Permission::query()->firstOrCreate(
                        ['code' => "$group.$action"],
                        ['name' => ucfirst($action).' '.str_replace('_', ' ', $group)],
                    );
                }
            }
            foreach (['SUPER_ADMIN' => 'Super administrator', 'ORDER_EMPLOYEE' => 'Order employee', 'CAPTAIN' => 'Captain'] as $code => $name) {
                $role = Role::query()->firstOrNew(['code' => $code]);
                if (! $role->exists) {
                    $role->name = $name;
                    $role->is_system = true;
                    $role->save();
                }
                if ($code === 'ORDER_EMPLOYEE') {
                    $role->permissions()->syncWithoutDetaching(Permission::whereIn('code', [
                        'branches.view',
                        'customers.view',
                        'customers.create',
                        'customers.update',
                        'customers.activate',
                        'customers.deactivate',
                        'areas.view',
                        'captains.view',
                        'captain_branches.view',
                        'captain_groups.view',
                        'categories.view',
                        'units.view',
                        'products.view',
                        'product_groups.view',
                        'shop_types.view',
                        'shops.view',
                        'shop_products.view',
                        'product_prices.view',
                        'product_prices.create',
                        'product_prices.reports',
                        'product_prices.estimate',
                        'orders.view',
                        'orders.create',
                        'orders.update',
                        'orders.status.update',
                        'orders.pricing.update',
                        'orders.captains.assign',
                        'orders.captains.remove',
                        'orders.items.change',
                        'delivery_pricing.view',
                        'delivery_pricing.apply',
                        'delivery_pricing.override',
                        'delivery_discounts.apply',
                    ])->pluck('id'));
                }
            }
        });
    }
}
