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
                    ])->pluck('id'));
                }
            }
        });
    }
}
