<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBranchAssignment;
use App\Models\CaptainEmploymentPeriod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CaptainManagementTest extends TestCase
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

    private function captain(Branch $branch, array $attributes = []): Captain
    {
        $user = User::factory()->create();
        $this->seed(AuthorizationSeeder::class);
        $user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());

        return Captain::factory()->for($user)->create([
            'home_branch_id' => $branch->id,
            'operational_status' => CaptainOperationalStatus::Unavailable,
            ...$attributes,
        ]);
    }

    public function test_super_admin_creates_captain_and_explicitly_assigns_captain_role(): void
    {
        $admin = $this->account();
        $user = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->post(route('captains.store'), [
            'user_id' => $user->id,
            'home_branch_id' => $branch->id,
            'operational_status' => CaptainOperationalStatus::Available->value,
            'employee_code' => 'CAP-001',
            'hired_at' => '2026-01-10',
        ])->assertSessionHasNoErrors();

        $captain = Captain::where('user_id', $user->id)->sole();
        $this->assertTrue($captain->homeBranch->is($branch));
        $this->assertSame(CaptainOperationalStatus::Available, $captain->operational_status);
        $this->assertTrue($user->fresh()->roles()->where('code', 'CAPTAIN')->exists());
    }

    public function test_captain_requires_valid_unique_eligible_user(): void
    {
        $admin = $this->account();
        $branch = Branch::factory()->create();
        $captain = $this->captain($branch);
        $orderEmployee = $this->account('ORDER_EMPLOYEE');
        $superAdmin = $this->account();

        $payload = ['home_branch_id' => $branch->id, 'hired_at' => '2026-01-10'];
        $this->actingAs($admin)->post(route('captains.store'), ['user_id' => 999999, ...$payload])->assertSessionHasErrors('user_id');
        $this->post(route('captains.store'), ['user_id' => $captain->user_id, ...$payload])->assertSessionHasErrors('user_id');
        $this->post(route('captains.store'), ['user_id' => $orderEmployee->id, ...$payload])->assertSessionHasErrors('user_id');
        $this->post(route('captains.store'), ['user_id' => $superAdmin->id, ...$payload])->assertSessionHasErrors('user_id');
    }

    public function test_profile_edit_cannot_bypass_permanent_transfer_history(): void
    {
        [$home, $other] = Branch::factory()->count(2)->create();
        $captain = $this->captain($home);

        $this->actingAs($this->account())->put(route('captains.update', $captain), [
            'home_branch_id' => $other->id,
            'employee_code' => 'UPDATED',
        ])->assertSessionHasErrors('home_branch_id');
        $this->assertTrue($captain->fresh()->homeBranch->is($home));
    }

    public function test_captain_creation_cannot_target_an_inaccessible_home_branch(): void
    {
        [$assigned, $foreign] = Branch::factory()->count(2)->create();
        $this->seed(AuthorizationSeeder::class);
        $role = Role::create(['name' => 'Captain creator', 'code' => 'CAPTAIN_CREATOR']);
        $role->permissions()->sync(Permission::where('code', 'captains.create')->pluck('id'));
        $creator = User::factory()->create();
        $creator->roles()->attach($role);
        $creator->branches()->attach($assigned);
        $candidate = User::factory()->create();

        $this->actingAs($creator)->post(route('captains.store'), ['user_id' => $candidate->id, 'home_branch_id' => $foreign->id, 'hired_at' => '2026-01-10'])->assertNotFound();
        $this->assertDatabaseMissing('captains', ['user_id' => $candidate->id]);
        $this->assertFalse($candidate->fresh()->roles()->where('code', 'CAPTAIN')->exists());
    }

    public function test_operational_status_is_controlled_and_independent_from_account_activation(): void
    {
        $captain = $this->captain(Branch::factory()->create());
        $admin = $this->account();

        $this->actingAs($admin)->patch(route('captains.status', $captain), [
            'operational_status' => CaptainOperationalStatus::Suspended->value,
            'reason' => 'Safety review',
        ])->assertSessionHasNoErrors();
        $captain->refresh();
        $this->assertSame(CaptainOperationalStatus::Suspended, $captain->operational_status);
        $this->assertDatabaseHas('captain_status_changes', [
            'captain_id' => $captain->id,
            'previous_status' => CaptainOperationalStatus::Unavailable->value,
            'new_status' => CaptainOperationalStatus::Suspended->value,
            'reason' => 'Safety review',
            'changed_by_user_id' => $admin->id,
        ]);
        $this->assertNotNull($captain->statusChanges()->sole()->changed_at);
        $this->assertTrue($captain->user->is_active);
        $this->patch(route('captains.status', $captain), ['operational_status' => 'INVALID'])->assertSessionHasErrors('operational_status');
    }

    public function test_status_permission_is_database_driven_and_branch_scoped(): void
    {
        [$accessible, $foreign] = Branch::factory()->count(2)->create();
        $visibleCaptain = $this->captain($accessible);
        $foreignCaptain = $this->captain($foreign);
        $this->seed(AuthorizationSeeder::class);
        $role = Role::create(['name' => 'Operations lead', 'code' => 'OPERATIONS_LEAD']);
        $role->permissions()->sync(Permission::whereIn('code', ['captains.view', 'captains.status.update'])->pluck('id'));
        $operator = User::factory()->create();
        $operator->roles()->attach($role);
        $operator->branches()->attach($accessible);

        $this->actingAs($operator)->patch(route('captains.status', $visibleCaptain), [
            'operational_status' => CaptainOperationalStatus::Available->value,
        ])->assertSessionHasNoErrors();
        $this->assertSame(CaptainOperationalStatus::Available, $visibleCaptain->fresh()->operational_status);

        $this->patch(route('captains.status', $foreignCaptain), [
            'operational_status' => CaptainOperationalStatus::OnLeave->value,
        ])->assertForbidden();
        $this->assertSame(CaptainOperationalStatus::Unavailable, $foreignCaptain->fresh()->operational_status);
    }

    public function test_users_without_status_permission_cannot_update_status(): void
    {
        $branch = Branch::factory()->create();
        $captain = $this->captain($branch);
        $orderEmployee = $this->account('ORDER_EMPLOYEE', [$branch]);

        $this->actingAs($orderEmployee)->patch(route('captains.status', $captain), [
            'operational_status' => CaptainOperationalStatus::Available->value,
        ])->assertForbidden();
        $this->assertFalse($orderEmployee->hasPermission('captains.status.update'));
    }

    public function test_all_operational_statuses_are_set_manually_and_suspension_requires_reason(): void
    {
        $captain = $this->captain(Branch::factory()->create());
        $admin = $this->account();

        foreach ([CaptainOperationalStatus::Available, CaptainOperationalStatus::Unavailable, CaptainOperationalStatus::OnLeave] as $status) {
            $this->actingAs($admin)->patch(route('captains.status', $captain), [
                'operational_status' => $status->value,
            ])->assertSessionHasNoErrors();
            $this->assertSame($status, $captain->fresh()->operational_status);
        }

        $this->patch(route('captains.status', $captain), [
            'operational_status' => CaptainOperationalStatus::Suspended->value,
        ])->assertSessionHasErrors('reason');
        $this->patch(route('captains.status', $captain), [
            'operational_status' => CaptainOperationalStatus::Suspended->value,
            'reason' => 'Policy investigation',
        ])->assertSessionHasNoErrors();
        $this->assertSame(CaptainOperationalStatus::Suspended, $captain->fresh()->operational_status);
        $this->assertSame(4, $captain->statusChanges()->count());
    }

    public function test_creation_opens_employment_without_contract_end_date(): void
    {
        $admin = $this->account();
        $user = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->post(route('captains.store'), [
            'user_id' => $user->id,
            'home_branch_id' => $branch->id,
            'hired_at' => '2026-01-10',
            'ended_at' => '2026-12-31',
        ])->assertSessionHasErrors('ended_at');

        $this->post(route('captains.store'), [
            'user_id' => $user->id,
            'home_branch_id' => $branch->id,
            'hired_at' => '2026-01-10',
        ])->assertSessionHasNoErrors();

        $captain = Captain::whereBelongsTo($user)->sole();
        $period = $captain->employmentPeriods()->sole();
        $this->assertSame('2026-01-10', $period->started_at->toDateString());
        $this->assertNull($period->ended_at);
        $this->assertSame($admin->id, $period->started_by_user_id);
    }

    public function test_termination_and_rehire_preserve_identity_and_employment_history(): void
    {
        $admin = $this->account();
        $captain = $this->captain(Branch::factory()->create(), ['operational_status' => CaptainOperationalStatus::Available]);
        $firstPeriod = $captain->employmentPeriods()->create([
            'started_at' => '2025-01-01 08:00:00',
            'started_by_user_id' => $admin->id,
        ]);
        $userId = $captain->user_id;

        $this->actingAs($admin)->patch(route('captains.employment.terminate', $captain), [
            'ended_at' => '2026-01-01 17:00:00',
            'reason' => 'Employment concluded',
        ])->assertSessionHasNoErrors();

        $firstPeriod->refresh();
        $this->assertSame('Employment concluded', $firstPeriod->end_reason);
        $this->assertSame($admin->id, $firstPeriod->ended_by_user_id);
        $this->assertNotNull($firstPeriod->ended_at);
        $this->assertFalse($captain->user->fresh()->is_active);
        $this->assertSame(CaptainOperationalStatus::Unavailable, $captain->fresh()->operational_status);
        $this->assertModelExists($captain);
        $this->assertModelExists($captain->user);

        $this->post(route('captains.employment.rehire', $captain), [
            'started_at' => '2026-02-01 08:00:00',
            'reactivate_account' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame($userId, $captain->fresh()->user_id);
        $this->assertSame(1, Captain::where('user_id', $userId)->count());
        $this->assertSame(2, $captain->employmentPeriods()->count());
        $this->assertTrue($captain->user->fresh()->is_active);
        $this->assertDatabaseHas('captain_employment_periods', ['id' => $firstPeriod->id, 'end_reason' => 'Employment concluded']);
    }

    public function test_employment_periods_cannot_overlap_or_have_more_than_one_open_period(): void
    {
        $admin = $this->account();
        $captain = $this->captain(Branch::factory()->create());
        $captain->employmentPeriods()->create([
            'started_at' => '2025-01-01 08:00:00',
            'ended_at' => '2025-12-31 17:00:00',
        ]);

        $this->actingAs($admin)->post(route('captains.employment.rehire', $captain), [
            'started_at' => '2025-06-01 08:00:00',
        ])->assertSessionHasErrors('started_at');

        $captain->employmentPeriods()->create(['started_at' => '2026-01-01 08:00:00']);
        $this->post(route('captains.employment.rehire', $captain), [
            'started_at' => '2026-02-01 08:00:00',
        ])->assertSessionHasErrors('employment');
        $this->assertSame(2, CaptainEmploymentPeriod::whereBelongsTo($captain)->count());
    }

    public function test_no_captain_schedule_schema_exists(): void
    {
        foreach (['captain_schedule_overrides', 'captain_schedules', 'overtime_requests', 'late_arrival_requests'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        foreach (['shift_start_time', 'shift_end_time'] as $column) {
            $this->assertFalse(Schema::hasColumn('captains', $column));
        }
    }

    public function test_branch_visibility_uses_home_or_current_temporary_assignment(): void
    {
        [$home, $target, $other] = Branch::factory()->count(3)->create();
        $homeCaptain = $this->captain($home);
        $temporaryCaptain = $this->captain($other);
        $expiredCaptain = $this->captain($other);
        CaptainBranchAssignment::factory()->for($temporaryCaptain)->for($target, 'branch')->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        CaptainBranchAssignment::factory()->for($expiredCaptain)->for($target, 'branch')->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $employee = $this->account('ORDER_EMPLOYEE', [$home, $target]);

        $response = $this->actingAs($employee)->get(route('captains.index'));
        $response->assertOk()->assertSee($homeCaptain->user->name)->assertSee($temporaryCaptain->user->name)->assertDontSee($expiredCaptain->user->name);
        $this->get(route('captains.show', $expiredCaptain))->assertForbidden();
        $this->actingAs($this->account())->get(route('captains.show', $expiredCaptain))->assertOk();
    }

    public function test_active_operational_branches_returns_home_and_active_temporary_only(): void
    {
        [$home, $active, $expired] = Branch::factory()->count(3)->create();
        $captain = $this->captain($home);
        CaptainBranchAssignment::factory()->for($captain)->for($active, 'branch')->create(['starts_at' => now()->subHour(), 'ends_at' => null]);
        CaptainBranchAssignment::factory()->for($captain)->for($expired, 'branch')->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

        $this->assertEqualsCanonicalizing([$home->id, $active->id], $captain->activeOperationalBranches()->pluck('id')->all());
    }

    public function test_captain_account_has_no_admin_access_and_profile_has_no_delete_route(): void
    {
        $branch = Branch::factory()->create();
        $captain = $this->captain($branch);

        $this->actingAs($captain->user)->get(route('captains.index'))->assertForbidden();
        $this->post(route('captains.store'), [])->assertForbidden();
        $this->patch(route('captains.status', $captain), ['operational_status' => CaptainOperationalStatus::Available->value])->assertForbidden();
        $this->delete('/captains/'.$captain->id)->assertStatus(405);
        $this->assertModelExists($captain);
    }

    public function test_inactive_user_remains_blocked_and_captain_content_is_escaped(): void
    {
        $captain = $this->captain(Branch::factory()->create(), ['notes' => '<script>alert(1)</script>']);
        $admin = $this->account();
        $this->actingAs($admin)->get(route('captains.show', $captain))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $admin->forceFill(['is_active' => false])->save();

        $this->get(route('captains.show', $captain))->assertRedirect('/login');
    }
}
