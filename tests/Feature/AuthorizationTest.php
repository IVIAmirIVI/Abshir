<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'SUPER_ADMIN'): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());

        return $user;
    }

    private function userData(array $extra = []): array
    {
        return array_merge(['name' => 'New User', 'email' => 'new@example.test', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'], $extra);
    }

    public function test_super_admin_can_open_all_management_screens(): void
    {
        $admin = $this->account();
        $branch = Branch::factory()->create();
        foreach (['/admin/users', '/admin/users/create', "/admin/users/{$admin->id}/edit", '/admin/branches', '/admin/branches/create', "/admin/branches/{$branch->id}/edit", '/admin/roles', '/admin/roles/create', '/admin/roles/'.Role::first()->id.'/edit', '/admin/permissions'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public static function ordinaryRoles(): array
    {
        return [['ORDER_EMPLOYEE'], ['CAPTAIN']];
    }

    #[DataProvider('ordinaryRoles')]
    public function test_ordinary_roles_cannot_access_admin_management_or_mutate_records(string $role): void
    {
        $user = $this->account($role);
        $branch = Branch::factory()->create();
        $super = Role::where('code', 'SUPER_ADMIN')->sole();
        foreach (['/admin/users', '/admin/users/create', "/admin/users/{$user->id}/edit", '/admin/roles', '/admin/roles/create', "/admin/roles/{$super->id}/edit", '/admin/permissions', '/admin/branches/create', "/admin/branches/{$branch->id}/edit"] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
        $this->post('/admin/users', $this->userData())->assertForbidden();
        $this->put("/admin/users/{$user->id}", $this->userData(['role_ids' => [$super->id]]))->assertForbidden();
        $this->patch("/admin/users/{$user->id}/status", ['is_active' => false])->assertForbidden();
        $this->post('/admin/branches', ['name' => 'Forbidden', 'code' => 'NO'])->assertForbidden();
        $this->put("/admin/branches/{$branch->id}", ['name' => 'Forbidden', 'code' => 'NO'])->assertForbidden();
        $this->patch("/admin/branches/{$branch->id}/status", ['is_active' => false])->assertForbidden();
        $this->post('/admin/roles', ['name' => 'Forbidden', 'code' => 'NO'])->assertForbidden();
        $this->put("/admin/roles/{$super->id}", ['name' => 'Forbidden', 'code' => 'NO'])->assertForbidden();
        $this->assertFalse($user->fresh()->isSuperAdmin());
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_guest_cannot_access_management(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
        $this->post('/admin/branches', [])->assertRedirect('/login');
    }

    public function test_super_admin_creates_user_with_multiple_roles_and_branches_and_hashes_password(): void
    {
        $admin = $this->account();
        $branches = Branch::factory()->count(2)->create();
        $roles = Role::whereIn('code', ['ORDER_EMPLOYEE', 'CAPTAIN'])->pluck('id')->all();
        $this->actingAs($admin)->post('/admin/users', $this->userData(['role_ids' => $roles, 'branch_ids' => $branches->modelKeys()]))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));
        $user = User::where('email', 'new@example.test')->sole();
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertCount(2, $user->roles);
        $this->assertEqualsCanonicalizing($branches->modelKeys(), $user->availableBranches()->pluck('id')->all());
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->get('/admin/users')->assertDontSee($user->password);
    }

    public function test_admin_can_edit_user_remove_all_assignments_and_preserve_password(): void
    {
        $admin = $this->account();
        $user = $this->account('ORDER_EMPLOYEE');
        $user->branches()->attach(Branch::factory()->create());
        $hash = $user->password;
        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => 'Updated', 'email' => $user->email, 'password' => ''])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Updated', $user->fresh()->name);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertCount(0, $user->fresh()->roles);
        $this->assertCount(0, $user->fresh()->branches);
    }

    public function test_last_active_super_admin_cannot_be_deactivated_or_demoted(): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/status", ['is_active' => false])->assertSessionHasErrors('roles');
        $this->put("/admin/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role_ids' => []])->assertSessionHasErrors('roles');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_another_active_super_admin_allows_deactivation(): void
    {
        $admin = $this->account();
        $other = $this->account();
        $this->actingAs($admin)->patch("/admin/users/{$other->id}/status", ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->is_active);
        $this->patch("/admin/users/{$other->id}/status", ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_authenticated_user_is_logged_out_of_protected_routes(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        $user->is_active = false;
        $user->save();
        $this->get('/admin/users')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertFalse(Gate::forUser($user)->allows('users.view'));
    }

    public function test_branch_access_excludes_unassigned_and_inactive_branches(): void
    {
        $user = $this->account('ORDER_EMPLOYEE');
        $assigned = Branch::factory()->count(2)->create();
        $inactive = Branch::factory()->create(['is_active' => false]);
        $unassigned = Branch::factory()->create();
        $user->branches()->attach([...$assigned->modelKeys(), $inactive->id]);
        $this->assertEqualsCanonicalizing($assigned->modelKeys(), $user->availableBranches()->pluck('id')->all());
        $this->assertFalse($user->canAccessBranch($unassigned));
        $this->assertFalse($user->canAccessBranch($inactive));
        $this->actingAs($user)->get('/admin/branches')->assertOk()->assertSee($assigned[0]->name)->assertDontSee($unassigned->code)->assertDontSee($inactive->code);
    }

    public function test_super_admin_has_all_branches_and_future_permissions(): void
    {
        $admin = $this->account();
        $branches = Branch::factory()->count(2)->create();
        Branch::factory()->create(['is_active' => false]);
        $this->assertCount(3, $admin->availableBranches()->get());
        $this->assertTrue($admin->canAccessBranch($branches[0]));
        $this->assertTrue(Gate::forUser($admin)->allows('future.action'));
    }

    public function test_super_admin_manages_branches_without_deleting(): void
    {
        $this->actingAs($this->account())->post('/admin/branches', ['name' => 'Damascus', 'code' => 'DAM', 'address' => 'Center', 'phone' => '123'])->assertSessionHasNoErrors()->assertRedirect();
        $branch = Branch::where('code', 'DAM')->sole();
        $this->put("/admin/branches/{$branch->id}", ['name' => 'Damascus Main', 'code' => 'DAM'])->assertSessionHasNoErrors();
        $this->assertSame('Damascus Main', $branch->fresh()->name);
        $this->patch("/admin/branches/{$branch->id}/status", ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($branch->fresh()->is_active);
        $this->patch("/admin/branches/{$branch->id}/status", ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($branch->fresh()->is_active);
        $this->delete("/admin/branches/{$branch->id}")->assertStatus(405);
        $this->assertModelExists($branch);
    }

    public function test_role_permissions_can_be_created_changed_and_revoked(): void
    {
        $admin = $this->account();
        $permission = Permission::where('code', 'users.view')->sole();
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Viewer', 'code' => 'VIEWER', 'permission_ids' => [$permission->id]])->assertSessionHasNoErrors();
        $role = Role::where('code', 'VIEWER')->sole();
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $this->assertTrue(Gate::forUser($user)->allows('users.view'));
        $this->assertFalse(Gate::forUser($user)->allows('users.update'));
        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertForbidden();
        $this->actingAs($admin)->put("/admin/roles/{$role->id}", ['name' => 'Viewer revised', 'code' => 'VIEWER', 'permission_ids' => []])->assertSessionHasNoErrors();
        $this->assertFalse(Gate::forUser($user)->allows('users.view'));
        $this->assertSame('Viewer revised', $role->fresh()->name);
    }

    public function test_core_roles_are_protected_from_mutation(): void
    {
        $admin = $this->account();
        foreach (Role::all() as $role) {
            $this->actingAs($admin)->put("/admin/roles/{$role->id}", ['name' => 'Changed', 'code' => 'CHANGED'])->assertSessionHasErrors('role');
            $this->assertNotSame('CHANGED', $role->fresh()->code);
        }
    }

    public function test_delegated_user_editor_cannot_assign_roles_or_edit_super_admin(): void
    {
        $admin = $this->account();
        $role = Role::create(['name' => 'Editor', 'code' => 'EDITOR']);
        $role->permissions()->sync(Permission::whereIn('code', ['users.update', 'roles.assign', 'roles.update', 'users.create'])->pluck('id'));
        $editor = User::factory()->create();
        $editor->roles()->attach($role);
        $target = User::factory()->create();
        $this->actingAs($editor)->put("/admin/users/{$editor->id}", ['name' => 'Self', 'email' => $editor->email])->assertForbidden();
        $this->put("/admin/users/{$admin->id}", ['name' => 'Changed', 'email' => $admin->email])->assertForbidden();
        $this->put("/admin/users/{$target->id}", ['name' => 'Changed', 'email' => $target->email, 'role_ids' => [$role->id]])->assertSessionHasErrors('role_ids');
        $this->put("/admin/roles/{$role->id}", ['name' => 'Changed', 'code' => 'EDITOR'])->assertForbidden();
        $this->assertCount(0, $target->fresh()->roles);
    }

    public function test_branch_editor_cannot_access_unassigned_branch(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $role = Role::create(['name' => 'Branch editor', 'code' => 'BRANCH_EDITOR']);
        $role->permissions()->sync(Permission::where('code', 'branches.update')->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $branch = Branch::factory()->create();
        $this->actingAs($user)->put("/admin/branches/{$branch->id}", ['name' => 'Changed', 'code' => 'CHANGED'])->assertForbidden();
        $this->assertNotSame('CHANGED', $branch->fresh()->code);
    }

    public function test_seeders_are_idempotent_and_restore_required_default_permissions(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $role = Role::where('code', 'ORDER_EMPLOYEE')->sole();
        $role->permissions()->detach();
        $this->seed(AuthorizationSeeder::class);
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('permissions', 87);
        $this->assertEqualsCanonicalizing([
            'areas.view',
            'branches.view',
            'customers.activate',
            'customers.create',
            'customers.deactivate',
            'customers.update',
            'customers.view',
            'captain_branches.view',
            'captain_groups.view',
            'captains.view',
            'categories.view',
            'product_groups.view',
            'products.view',
            'product_prices.create',
            'product_prices.estimate',
            'product_prices.reports',
            'product_prices.view',
            'shop_products.view',
            'shop_types.view',
            'shops.view',
            'units.view',
        ], $role->fresh()->permissions->pluck('code')->all());
        $this->assertCount(0, Role::where('code', 'CAPTAIN')->sole()->permissions);
    }

    public function test_validation_rejects_invalid_assignments_duplicate_codes_and_status_in_regular_forms(): void
    {
        $this->actingAs($this->account())->post('/admin/users', $this->userData(['role_ids' => [9999], 'branch_ids' => [9999], 'is_active' => false]))
            ->assertSessionHasErrors(['role_ids.0', 'branch_ids.0', 'is_active']);
        $branch = Branch::factory()->create();
        $this->post('/admin/branches', ['name' => 'Duplicate', 'code' => $branch->code])->assertSessionHasErrors('code');
        $this->post('/admin/roles', ['name' => 'Duplicate', 'code' => 'CAPTAIN'])->assertSessionHasErrors('code');
        $this->post('/admin/roles', ['name' => 'Invalid', 'code' => 'INVALID', 'permission_ids' => [9999]])->assertSessionHasErrors('permission_ids.0');
    }

    public function test_profile_payload_cannot_change_authorization_or_activity(): void
    {
        $user = $this->account('CAPTAIN');
        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => $user->email, 'is_active' => false, 'role_ids' => [Role::where('code', 'SUPER_ADMIN')->sole()->id]])->assertRedirect();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    public function test_management_pages_escape_user_supplied_names(): void
    {
        $admin = $this->account();
        User::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($admin)->get('/admin/users')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_navigation_hides_unavailable_management_links(): void
    {
        $user = $this->account('CAPTAIN');
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee(route('admin.users.index'))->assertDontSee(route('admin.roles.index'));
        $this->actingAs($this->account())->get('/dashboard')->assertOk()->assertSee(route('admin.users.index'));
    }
}
