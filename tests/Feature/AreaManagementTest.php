<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'SUPER_ADMIN', array $branches = []): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    private function areaEditor(array $branches): User
    {
        $this->seed(AuthorizationSeeder::class);
        $role = Role::firstOrCreate(['code' => 'AREA_EDITOR'], ['name' => 'Area editor']);
        $role->permissions()->sync(Permission::where('code', 'like', 'areas.%')->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    public function test_area_lists_are_branch_scoped_while_super_admin_sees_every_branch(): void
    {
        [$assigned, $other] = Branch::factory()->count(2)->create();
        $visible = Area::factory()->for($assigned)->create();
        $hidden = Area::factory()->for($other)->create();
        $employee = $this->account('ORDER_EMPLOYEE', [$assigned]);

        $this->actingAs($employee)->get(route('areas.index', ['branch_id' => $assigned]))->assertOk()->assertSee($visible->name)->assertDontSee($hidden->name);
        $this->get(route('areas.index', ['branch_id' => $other]))->assertForbidden();
        $this->actingAs($this->account())->get(route('areas.index', ['branch_id' => $other]))->assertOk()->assertSee($hidden->name);
    }

    public function test_employee_can_view_but_cannot_mutate_areas(): void
    {
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $employee = $this->account('ORDER_EMPLOYEE', [$branch]);

        $this->actingAs($employee)->get(route('areas.edit', $area))->assertForbidden();
        $this->post(route('areas.store'), ['branch_id' => $branch->id, 'name' => 'Blocked'])->assertForbidden();
        $this->patch(route('areas.status', $area), ['is_active' => false])->assertForbidden();
    }

    public function test_editor_can_create_update_and_toggle_area_without_delete_route(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->areaEditor([$branch]);
        $this->actingAs($user)->post(route('areas.store'), ['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CTR'])->assertSessionHasNoErrors();
        $area = Area::where('code', 'CTR')->sole();
        $this->put(route('areas.update', $area), ['branch_id' => $branch->id, 'name' => 'Central Zone', 'code' => 'CTR'])->assertSessionHasNoErrors();
        $this->patch(route('areas.status', $area), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($area->fresh()->is_active);
        $this->delete('/areas/'.$area->id)->assertStatus(405);
    }

    public function test_cross_branch_parent_and_inaccessible_direct_edit_are_rejected(): void
    {
        [$one, $two] = Branch::factory()->count(2)->create();
        $parent = Area::factory()->for($two)->create();
        $foreign = Area::factory()->for($two)->create();
        $editor = $this->areaEditor([$one]);
        $admin = $this->account();

        $this->actingAs($admin)->post(route('areas.store'), ['branch_id' => $one->id, 'parent_id' => $parent->id, 'name' => 'Invalid'])->assertSessionHasErrors('parent_id');
        $this->actingAs($editor);
        $this->get(route('areas.edit', $foreign))->assertForbidden();
        $this->put(route('areas.update', $foreign), ['branch_id' => $two->id, 'name' => 'Tampered'])->assertForbidden();
    }

    public function test_hierarchy_rejects_self_parent_and_descendant_cycles(): void
    {
        $branch = Branch::factory()->create();
        $parent = Area::factory()->for($branch)->create();
        $child = Area::factory()->for($branch)->create(['parent_id' => $parent->id]);
        $admin = $this->account();

        $this->actingAs($admin)->put(route('areas.update', $parent), ['branch_id' => $branch->id, 'parent_id' => $parent->id, 'name' => $parent->name])->assertSessionHasErrors('parent_id');
        $this->put(route('areas.update', $parent), ['branch_id' => $branch->id, 'parent_id' => $child->id, 'name' => $parent->name])->assertSessionHasErrors('parent_id');
        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_area_with_dependents_cannot_move_branches(): void
    {
        [$one, $two] = Branch::factory()->count(2)->create();
        $area = Area::factory()->for($one)->create();
        CustomerAddress::factory()->for(Customer::factory())->for($area)->create();

        $this->actingAs($this->account())->put(route('areas.update', $area), ['branch_id' => $two->id, 'name' => $area->name])->assertSessionHasErrors('branch_id');
        $this->assertTrue($area->fresh()->branch->is($one));
    }

    public function test_inactive_area_remains_visible_but_cannot_receive_new_address(): void
    {
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create(['is_active' => false]);
        $customer = Customer::factory()->create();
        $admin = $this->account();

        $this->actingAs($admin)->get(route('areas.index', ['branch_id' => $branch]))->assertOk()->assertSee($area->name)->assertSee('Inactive');
        $this->post(route('customers.addresses.store', $customer), ['area_id' => $area->id, 'address_line_1' => 'Blocked'])->assertSessionHasErrors('area_id');
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_area_pages_escape_user_content(): void
    {
        $branch = Branch::factory()->create();
        Area::factory()->for($branch)->create(['name' => '<script>alert(1)</script>']);

        $this->actingAs($this->account())->get(route('areas.index', ['branch_id' => $branch]))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
