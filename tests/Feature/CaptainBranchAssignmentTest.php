<?php

namespace Tests\Feature;

use App\Enums\CaptainAssignmentType;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBranchAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptainBranchAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function manager(array $permissions, array $branches): User
    {
        $this->seed(AuthorizationSeeder::class);
        $role = Role::create(['name' => fake()->unique()->word(), 'code' => fake()->unique()->bothify('ROLE_####')]);
        $role->permissions()->sync(Permission::whereIn('code', $permissions)->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    private function captain(Branch $home): Captain
    {
        return Captain::factory()->create(['home_branch_id' => $home->id]);
    }

    public function test_temporary_assignment_to_other_branch_succeeds_and_home_does_not_change(): void
    {
        [$home, $target] = Branch::factory()->count(2)->create();
        $captain = $this->captain($home);
        $manager = $this->manager(['captain_branches.assign'], [$home, $target]);

        $this->actingAs($manager)->post(route('captains.assignments.store', $captain), [
            'branch_id' => $target->id,
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
            'reason' => 'Temporary coverage',
        ])->assertSessionHasNoErrors();

        $assignment = CaptainBranchAssignment::sole();
        $this->assertSame(CaptainAssignmentType::Temporary, $assignment->assignment_type);
        $this->assertTrue($captain->fresh()->homeBranch->is($home));
        $this->assertTrue($assignment->createdBy->is($manager));
    }

    public function test_temporary_assignment_rejects_home_branch_and_invalid_interval(): void
    {
        [$home, $target] = Branch::factory()->count(2)->create();
        $captain = $this->captain($home);
        $manager = $this->manager(['captain_branches.assign'], [$home, $target]);

        $this->actingAs($manager)->post(route('captains.assignments.store', $captain), ['branch_id' => $home->id, 'starts_at' => now()->toDateTimeString()])->assertSessionHasErrors('branch_id');
        $this->post(route('captains.assignments.store', $captain), ['branch_id' => $target->id, 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->toDateTimeString()])->assertSessionHasErrors('ends_at');
    }

    public function test_overlapping_temporary_assignments_are_rejected(): void
    {
        [$home, $first, $second] = Branch::factory()->count(3)->create();
        $captain = $this->captain($home);
        CaptainBranchAssignment::factory()->for($captain)->for($first, 'branch')->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3)]);
        $manager = $this->manager(['captain_branches.assign'], [$home, $second]);

        $this->actingAs($manager)->post(route('captains.assignments.store', $captain), [
            'branch_id' => $second->id,
            'starts_at' => now()->addHour()->toDateTimeString(),
            'ends_at' => now()->addHours(2)->toDateTimeString(),
        ])->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('captain_branch_assignments', 1);
    }

    public function test_ending_assignment_preserves_history_and_records_actor(): void
    {
        [$home, $target] = Branch::factory()->count(2)->create();
        $captain = $this->captain($home);
        $assignment = CaptainBranchAssignment::factory()->for($captain)->for($target, 'branch')->create(['starts_at' => now()->subHour(), 'ends_at' => null]);
        $manager = $this->manager(['captain_branches.assign'], [$home, $target]);

        $this->actingAs($manager)->patch(route('captains.assignments.end', [$captain, $assignment]))->assertSessionHasNoErrors();
        $assignment->refresh();
        $this->assertNotNull($assignment->ends_at);
        $this->assertSame($manager->id, $assignment->ended_by_user_id);
        $this->assertDatabaseHas('captain_branch_assignments', ['id' => $assignment->id]);
    }

    public function test_assignment_requires_access_to_home_and_target_branches(): void
    {
        [$home, $target] = Branch::factory()->count(2)->create();
        $captain = $this->captain($home);
        $manager = $this->manager(['captain_branches.assign'], [$home]);

        $this->actingAs($manager)->post(route('captains.assignments.store', $captain), ['branch_id' => $target->id, 'starts_at' => now()->toDateTimeString()])->assertForbidden();
    }

    public function test_permanent_transfer_updates_home_and_records_complete_history(): void
    {
        [$old, $new] = Branch::factory()->count(2)->create();
        $captain = $this->captain($old);
        $manager = $this->manager(['captain_branches.transfer'], [$old, $new]);

        $this->actingAs($manager)->post(route('captains.transfer.store', $captain), [
            'branch_id' => $new->id,
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Permanent operational move',
        ])->assertSessionHasNoErrors();

        $transfer = CaptainBranchAssignment::where('assignment_type', CaptainAssignmentType::PermanentTransfer->value)->sole();
        $this->assertTrue($captain->fresh()->homeBranch->is($new));
        $this->assertSame($old->id, $transfer->from_branch_id);
        $this->assertSame($new->id, $transfer->branch_id);
        $this->assertSame('Permanent operational move', $transfer->reason);
        $this->assertSame($manager->id, $transfer->created_by_user_id);
    }

    public function test_transfer_requires_reason_different_branch_and_access_to_both(): void
    {
        [$home, $target] = Branch::factory()->count(2)->create();
        $captain = $this->captain($home);
        $manager = $this->manager(['captain_branches.transfer'], [$home, $target]);

        $this->actingAs($manager)->post(route('captains.transfer.store', $captain), ['branch_id' => $target->id, 'starts_at' => now()->toDateTimeString()])->assertSessionHasErrors('reason');
        $this->post(route('captains.transfer.store', $captain), ['branch_id' => $home->id, 'starts_at' => now()->toDateTimeString(), 'reason' => 'Same'])->assertSessionHasErrors('branch_id');
        $unauthorized = $this->manager(['captain_branches.transfer'], [$home]);
        $this->actingAs($unauthorized)->post(route('captains.transfer.store', $captain), ['branch_id' => $target->id, 'starts_at' => now()->toDateTimeString(), 'reason' => 'Blocked'])->assertForbidden();
    }

    public function test_transfer_preserves_temporary_history_and_nested_tampering_is_denied(): void
    {
        [$old, $temporary, $new] = Branch::factory()->count(3)->create();
        $captain = $this->captain($old);
        $otherCaptain = $this->captain($old);
        $assignment = CaptainBranchAssignment::factory()->for($captain)->for($temporary, 'branch')->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $manager = $this->manager(['captain_branches.assign', 'captain_branches.transfer'], [$old, $temporary, $new]);

        $this->actingAs($manager)->post(route('captains.transfer.store', $captain), ['branch_id' => $new->id, 'starts_at' => now()->toDateTimeString(), 'reason' => 'Transfer'])->assertSessionHasNoErrors();
        $this->assertModelExists($assignment);
        $this->patch(route('captains.assignments.end', [$otherCaptain, $assignment]))->assertNotFound();

        $this->assertDatabaseCount('captain_branch_assignments', 2);
    }
}
