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
                'orders' => ['view', 'create', 'update', 'status.update', 'pricing.update', 'captains.assign', 'captains.remove', 'items.change', 'historical_entry', 'cancel', 'cancel.finalize'],
                'order_shop_receipts' => ['upload', 'view'],
                'order_shop_purchases' => ['confirm', 'view'],
                'order_cancellation_intents' => ['create', 'view'],
                'order_shop_returns' => ['create', 'view'],
                'order_shop_return_evidence' => ['download'],
                'captain_ratings' => ['record', 'view_summary'],
                'delivery_pricing' => ['view', 'apply', 'override'],
                'delivery_pricing_rules' => ['view', 'create', 'update', 'activate', 'deactivate'],
                'delivery_pricing_overrides' => ['approve'],
                'delivery_discounts' => ['apply'],
                'delivery_discount_limits' => ['manage'],
                'delivery_financial_details' => ['view'],
                'invoices' => ['view', 'create', 'issue', 'void', 'replace'],
                'invoice_collections' => ['view', 'create', 'sham_cash.view'],
                'invoice_corrections' => ['view', 'request', 'prepare', 'finalize'],
                'customer_refunds' => ['record', 'view_evidence'],
                'captain_receivable_recoveries' => ['record', 'view_evidence'],
                'system_settings' => ['view', 'update'],
                'company_financials' => ['view'],
                'captain_financials' => ['view'],
                'captain_deposits' => ['view', 'create'],
                'captain_deposit_manual_adjustments' => ['create'],
                'captain_receivable_payouts' => ['view', 'create'],
                'captain_financial_evidence' => ['download'],
                'captain_bonuses' => ['create', 'submit', 'approve'],
                'captain_expenses' => ['create', 'submit', 'approve'],
                'bill_payments' => ['record', 'view'],
                'bill_payment_fees' => ['manage'],
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
                        'captain_bonuses.create',
                        'captain_bonuses.submit',
                        'captain_expenses.create',
                        'captain_expenses.submit',
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
                        'orders.historical_entry',
                        'orders.cancel',
                        'orders.cancel.finalize',
                        'order_shop_receipts.upload',
                        'order_shop_receipts.view',
                        'order_shop_purchases.confirm',
                        'order_shop_purchases.view',
                        'order_cancellation_intents.create',
                        'order_cancellation_intents.view',
                        'order_shop_returns.create',
                        'order_shop_returns.view',
                        'order_shop_return_evidence.download',
                        'captain_ratings.record',
                        'delivery_pricing.view',
                        'delivery_pricing.apply',
                        'delivery_pricing.override',
                        'delivery_discounts.apply',
                        'invoices.view',
                        'invoices.create',
                        'invoices.issue',
                        'invoice_collections.view',
                        'invoice_collections.create',
                        'invoice_corrections.view',
                        'invoice_corrections.request',
                        'customer_refunds.view_evidence',
                        'captain_receivable_recoveries.record',
                        'captain_receivable_recoveries.view_evidence',
                        'bill_payments.record',
                        'bill_payments.view',
                        'captain_financials.view',
                        'captain_deposits.view',
                        'captain_deposits.create',
                        'captain_receivable_payouts.view',
                        'captain_receivable_payouts.create',
                        'captain_financial_evidence.download',
                    ])->pluck('id'));
                }
            }
        });
    }
}
