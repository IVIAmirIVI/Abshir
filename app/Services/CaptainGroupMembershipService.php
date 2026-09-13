<?php

namespace App\Services;

use App\Models\Captain;
use App\Models\CaptainGroup;
use App\Models\CaptainGroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaptainGroupMembershipService
{
    public function join(User $actor, Captain $captain, CaptainGroup $group, array $data): CaptainGroupMember
    {
        Gate::forUser($actor)->authorize('captain_groups.assign');
        abort_unless($actor->canAccessCaptain($captain), 403);
        if (! $group->is_active) {
            throw ValidationException::withMessages(['captain_group_id' => 'An inactive group cannot receive new memberships.']);
        }

        return DB::transaction(function () use ($actor, $captain, $group, $data): CaptainGroupMember {
            Captain::query()->lockForUpdate()->findOrFail($captain->id);
            $joinedAt = Carbon::parse($data['joined_at']);
            $leftAt = isset($data['left_at']) ? Carbon::parse($data['left_at']) : null;
            if ($leftAt && $leftAt->lte($joinedAt)) {
                throw ValidationException::withMessages(['left_at' => 'The membership end must be after its start.']);
            }
            $overlap = $captain->groupMemberships()->where('captain_group_id', $group->id)
                ->where(fn (Builder $existing) => $existing->whereNull('left_at')->orWhere('left_at', '>', $joinedAt));
            if ($leftAt) {
                $overlap->where('joined_at', '<', $leftAt);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['joined_at' => 'This membership overlaps an existing membership in the group.']);
            }

            $membership = $captain->groupMemberships()->make([
                'captain_group_id' => $group->id,
                'joined_at' => $joinedAt,
                'left_at' => $leftAt,
            ]);
            $membership->created_by_user_id = $actor->id;
            $membership->save();

            return $membership;
        });
    }

    public function end(User $actor, Captain $captain, CaptainGroupMember $membership): void
    {
        Gate::forUser($actor)->authorize('captain_groups.assign');
        abort_unless($actor->canAccessCaptain($captain), 403);
        abort_if($membership->captain_id !== $captain->id, 404);
        if (! $membership->newQuery()->whereKey($membership)->activeAt(now())->exists()) {
            throw ValidationException::withMessages(['membership' => 'Only a currently active membership can be ended.']);
        }

        DB::transaction(function () use ($actor, $membership): void {
            $membership = CaptainGroupMember::query()->lockForUpdate()->findOrFail($membership->id);
            $membership->left_at = now();
            $membership->ended_by_user_id = $actor->id;
            $membership->save();
        });
    }
}
