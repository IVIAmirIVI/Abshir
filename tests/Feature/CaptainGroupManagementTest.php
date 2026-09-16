<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainGroup;
use App\Models\CaptainGroupMember;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CaptainGroupManagementTest extends TestCase
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

    private function manager(array $permissions, Branch $branch): User
    {
        $this->seed(AuthorizationSeeder::class);
        $role = Role::create(['name' => fake()->unique()->word(), 'code' => fake()->unique()->bothify('GROUP_ROLE_####')]);
        $role->permissions()->sync(Permission::whereIn('code', $permissions)->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach($branch);

        return $user;
    }

    public function test_group_can_be_created_updated_deactivated_without_hard_delete(): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->post(route('captain-groups.store'), ['name' => 'Night Shift', 'code' => 'NIGHT', 'description' => 'Night operations'])->assertSessionHasNoErrors();
        $group = CaptainGroup::where('code', 'NIGHT')->sole();
        $this->put(route('captain-groups.update', $group), ['name' => 'Night Team', 'code' => 'NIGHT'])->assertSessionHasNoErrors();
        $this->patch(route('captain-groups.status', $group), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($group->fresh()->is_active);
        $this->delete('/captain-groups/'.$group->id)->assertStatus(405);
    }

    public function test_captain_can_join_multiple_groups_with_join_dates(): void
    {
        $branch = Branch::factory()->create();
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        [$first, $second] = CaptainGroup::factory()->count(2)->create();
        $manager = $this->manager(['captain_groups.assign'], $branch);

        foreach ([$first, $second] as $index => $group) {
            $this->actingAs($manager)->post(route('captains.groups.store', $captain), ['captain_group_id' => $group->id, 'joined_at' => now()->subDays($index + 1)->toDateTimeString()])->assertSessionHasNoErrors();
        }
        $this->assertCount(2, $captain->groupMemberships);
        $this->assertNotNull($captain->groupMemberships->first()->joined_at);
    }

    public function test_ending_membership_preserves_row_and_records_actor(): void
    {
        $branch = Branch::factory()->create();
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $membership = CaptainGroupMember::factory()->for($captain)->create(['joined_at' => now()->subDay(), 'left_at' => null]);
        $manager = $this->manager(['captain_groups.assign'], $branch);

        $this->actingAs($manager)->patch(route('captains.groups.end', [$captain, $membership]))->assertSessionHasNoErrors();
        $membership->refresh();
        $this->assertNotNull($membership->left_at);
        $this->assertSame($manager->id, $membership->ended_by_user_id);
        $this->assertModelExists($membership);
    }

    public function test_overlapping_same_group_membership_is_rejected_but_later_membership_is_allowed(): void
    {
        $branch = Branch::factory()->create();
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $group = CaptainGroup::factory()->create();
        CaptainGroupMember::factory()->for($captain)->for($group, 'group')->create(['joined_at' => '2026-01-01 00:00:00', 'left_at' => '2026-02-01 00:00:00']);
        $manager = $this->manager(['captain_groups.assign'], $branch);

        $this->actingAs($manager)->post(route('captains.groups.store', $captain), ['captain_group_id' => $group->id, 'joined_at' => '2026-01-15 00:00:00'])->assertSessionHasErrors('joined_at');
        $this->post(route('captains.groups.store', $captain), ['captain_group_id' => $group->id, 'joined_at' => '2026-02-01 00:00:00'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('captain_group_members', 2);
    }

    public function test_membership_at_time_query_uses_historical_range(): void
    {
        $captain = Captain::factory()->create();
        $group = CaptainGroup::factory()->create();
        CaptainGroupMember::factory()->for($captain)->for($group, 'group')->create(['joined_at' => '2026-01-01 00:00:00', 'left_at' => '2026-02-01 00:00:00']);

        $this->assertTrue($captain->isMemberOfAt($group, Carbon::parse('2026-01-15')));
        $this->assertFalse($captain->isMemberOfAt($group, Carbon::parse('2026-02-01')));
    }

    public function test_inactive_group_remains_in_history_but_rejects_new_membership(): void
    {
        $branch = Branch::factory()->create();
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $group = CaptainGroup::factory()->create(['name' => 'Historic Group', 'is_active' => false]);
        CaptainGroupMember::factory()->for($captain)->for($group, 'group')->create(['joined_at' => now()->subMonth(), 'left_at' => now()->subDay()]);
        $manager = $this->manager(['captains.view', 'captain_groups.view', 'captain_groups.assign'], $branch);

        $this->actingAs($manager)->get(route('captains.show', $captain))->assertOk()->assertSee('Historic Group');
        $this->post(route('captains.groups.store', $captain), ['captain_group_id' => $group->id, 'joined_at' => now()->toDateTimeString()])->assertSessionHasErrors('captain_group_id');
    }

    public function test_order_employee_has_only_read_permissions_and_seed_is_idempotent(): void
    {
        $employee = $this->account('ORDER_EMPLOYEE');
        $this->seed(AuthorizationSeeder::class);
        $this->assertTrue($employee->can('captains.view'));
        $this->assertTrue($employee->can('captain_branches.view'));
        $this->assertTrue($employee->can('captain_groups.view'));
        foreach (['captains.create', 'captains.update', 'captains.status.update', 'captain_branches.assign', 'captain_branches.transfer', 'captain_groups.create', 'captain_groups.update', 'captain_groups.assign'] as $ability) {
            $this->assertFalse($employee->can($ability));
        }
        $this->actingAs($employee)->get(route('captain-groups.create'))->assertForbidden();
        $this->assertDatabaseCount('permissions', 115);
        $this->assertDatabaseCount('roles', 3);
    }

    public function test_group_content_is_escaped_and_membership_identifier_tampering_is_denied(): void
    {
        $branch = Branch::factory()->create();
        [$captain, $other] = Captain::factory()->count(2)->create(['home_branch_id' => $branch->id]);
        $group = CaptainGroup::factory()->create(['name' => '<script>alert(1)</script>']);
        $membership = CaptainGroupMember::factory()->for($captain)->for($group, 'group')->create(['joined_at' => now()->subDay(), 'left_at' => null]);
        $admin = $this->account();

        $this->actingAs($admin)->get(route('captain-groups.index'))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->patch(route('captains.groups.end', [$other, $membership]))->assertNotFound();

        $this->assertNull($membership->fresh()->left_at);
    }
}
