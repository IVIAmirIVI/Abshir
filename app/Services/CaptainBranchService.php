<?php

namespace App\Services;

use App\Enums\CaptainAssignmentType;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBranchAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaptainBranchService
{
    public function assignTemporary(User $actor, Captain $captain, array $data): CaptainBranchAssignment
    {
        Gate::forUser($actor)->authorize('captain_branches.assign');

        return DB::transaction(function () use ($actor, $captain, $data): CaptainBranchAssignment {
            $captain = Captain::query()->lockForUpdate()->findOrFail($captain->id);
            $branch = Branch::query()->findOrFail($data['branch_id']);
            $this->assertBranchAccess($actor, $captain->homeBranch, $branch);
            if ($branch->is($captain->homeBranch)) {
                throw ValidationException::withMessages(['branch_id' => 'A temporary assignment must use a branch other than the home branch.']);
            }
            abort_unless($branch->is_active, 422, 'The target branch must be active.');
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;
            if ($endsAt && $endsAt->lte($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => 'The end time must be after the start time.']);
            }
            $this->assertNoTemporaryOverlap($captain, $startsAt, $endsAt);

            $assignment = $captain->branchAssignments()->make([
                ...$data,
                'assignment_type' => CaptainAssignmentType::Temporary,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
            $assignment->created_by_user_id = $actor->id;
            $assignment->save();

            return $assignment;
        });
    }

    public function endTemporary(User $actor, Captain $captain, CaptainBranchAssignment $assignment): void
    {
        Gate::forUser($actor)->authorize('captain_branches.assign');
        abort_if($assignment->captain_id !== $captain->id || $assignment->assignment_type !== CaptainAssignmentType::Temporary, 404);
        $this->assertBranchAccess($actor, $captain->homeBranch, $assignment->branch);
        if (! $assignment->newQuery()->whereKey($assignment)->activeAt(now())->exists()) {
            throw ValidationException::withMessages(['assignment' => 'Only a currently active temporary assignment can be ended.']);
        }

        DB::transaction(function () use ($actor, $assignment): void {
            $assignment = CaptainBranchAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $assignment->ends_at = now();
            $assignment->ended_by_user_id = $actor->id;
            $assignment->save();
        });
    }

    public function transfer(User $actor, Captain $captain, array $data): CaptainBranchAssignment
    {
        Gate::forUser($actor)->authorize('captain_branches.transfer');

        return DB::transaction(function () use ($actor, $captain, $data): CaptainBranchAssignment {
            $captain = Captain::query()->lockForUpdate()->findOrFail($captain->id);
            $oldBranch = $captain->homeBranch;
            $newBranch = Branch::query()->findOrFail($data['branch_id']);
            $this->assertBranchAccess($actor, $oldBranch, $newBranch);
            if ($newBranch->is($oldBranch)) {
                throw ValidationException::withMessages(['branch_id' => 'The new home branch must differ from the current home branch.']);
            }
            abort_unless($newBranch->is_active, 422, 'The new home branch must be active.');
            $startsAt = Carbon::parse($data['starts_at']);
            if ($startsAt->isFuture()) {
                throw ValidationException::withMessages(['starts_at' => 'A permanent transfer cannot take effect in the future.']);
            }
            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['reason' => 'A permanent transfer reason is required.']);
            }

            $transfer = $captain->branchAssignments()->make([
                'assignment_type' => CaptainAssignmentType::PermanentTransfer,
                'from_branch_id' => $oldBranch->id,
                'branch_id' => $newBranch->id,
                'starts_at' => $startsAt,
                'reason' => $data['reason'],
            ]);
            $transfer->created_by_user_id = $actor->id;
            $transfer->save();
            $captain->home_branch_id = $newBranch->id;
            $captain->save();

            return $transfer;
        });
    }

    private function assertNoTemporaryOverlap(Captain $captain, Carbon $startsAt, ?Carbon $endsAt): void
    {
        $overlap = $captain->branchAssignments()->temporary()
            ->where(fn (Builder $existing) => $existing->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt));
        if ($endsAt) {
            $overlap->where('starts_at', '<', $endsAt);
        }
        if ($overlap->exists()) {
            throw ValidationException::withMessages(['starts_at' => 'This temporary assignment overlaps an existing temporary assignment.']);
        }
    }

    private function assertBranchAccess(User $actor, Branch $home, Branch $target): void
    {
        abort_unless($actor->canAccessBranch($home) && $actor->canAccessBranch($target), 403);
    }
}
