<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase4AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, array $branches = []): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    public function test_order_employee_has_only_intended_phase_four_read_permissions(): void
    {
        $employee = $this->account('ORDER_EMPLOYEE');
        foreach (['categories.view', 'units.view', 'products.view', 'product_groups.view', 'shop_types.view', 'shops.view', 'shop_products.view'] as $permission) {
            $this->assertTrue($employee->hasPermission($permission));
        }
        foreach (Permission::whereIn('code', ['categories.create', 'units.update', 'products.create', 'product_groups.assign', 'shop_types.create', 'shops.update', 'shop_products.create', 'shop_partnerships.create'])->pluck('code') as $permission) {
            $this->assertFalse($employee->hasPermission($permission));
        }
    }

    public function test_captain_cannot_administer_catalog_or_shops(): void
    {
        $captain = $this->account('CAPTAIN');
        foreach (['/categories', '/units', '/products', '/product-groups', '/shop-types', '/shops'] as $url) {
            $this->actingAs($captain)->get($url)->assertForbidden();
        }
        $this->post('/products', [])->assertForbidden();
        $this->post('/shops', [])->assertForbidden();
    }

    public function test_permission_does_not_bypass_inaccessible_shop_branch(): void
    {
        [$assigned, $foreign] = Branch::factory()->count(2)->create();
        $shop = Shop::create(['name' => 'Foreign', 'area_id' => Area::factory()->for($foreign)->create()->id]);
        $this->seed(AuthorizationSeeder::class);
        $role = Role::create(['name' => 'Shop editor', 'code' => 'SHOP_EDITOR']);
        $role->permissions()->sync(Permission::whereIn('code', ['shops.view', 'shops.update'])->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach($assigned);

        $this->actingAs($user)->get(route('shops.edit', $shop))->assertForbidden();
        $this->put(route('shops.update', $shop), ['name' => 'Tampered', 'area_id' => $shop->area_id, 'is_mobile_visible' => false, 'accepts_online_orders' => false])->assertForbidden();
        $this->assertSame('Foreign', $shop->fresh()->name);
    }

    public function test_seeder_is_idempotent_and_no_phase_seven_domain_schema_exists(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $this->seed(AuthorizationSeeder::class);
        $this->assertDatabaseCount('permissions', 147);
        $this->assertCount(0, Role::where('code', 'CAPTAIN')->sole()->permissions);
        foreach (['payments', 'ledgers', 'commissions', 'incentives', 'inventories'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
