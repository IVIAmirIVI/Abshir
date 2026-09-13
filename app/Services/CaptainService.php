<?php

namespace App\Services;

use App\Models\Captain;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaptainService
{
    public function save(User $actor, Captain $captain, array $data): Captain
    {
        Gate::forUser($actor)->authorize($captain->exists ? 'captains.update' : 'captains.create');

        return DB::transaction(function () use ($actor, $captain, $data): Captain {
            if ($captain->exists) {
                $captain = Captain::query()->lockForUpdate()->findOrFail($captain->id);
                abort_unless($actor->canAccessCaptain($captain), 403);
                if (isset($data['user_id']) && (int) $data['user_id'] !== $captain->user_id) {
                    throw ValidationException::withMessages(['user_id' => 'A Captain profile cannot be relinked to another account.']);
                }
            } else {
                $user = User::query()->lockForUpdate()->findOrFail($data['user_id']);
                if ($user->captain()->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'This user already has a Captain profile.']);
                }
                if ($user->roles()->whereIn('code', [Role::SUPER_ADMIN, 'ORDER_EMPLOYEE'])->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'Administrative and order employee accounts cannot be linked as Captains.']);
                }
                $captainRole = Role::where('code', 'CAPTAIN')->sole();
                $user->roles()->syncWithoutDetaching([$captainRole->id]);
            }

            if (! $captain->exists) {
                $branch = $actor->availableBranches()->whereKey($data['home_branch_id'])->firstOrFail();
                if (! $branch->is_active) {
                    throw ValidationException::withMessages(['home_branch_id' => 'The home branch must be active.']);
                }
            }
            $captain->fill($data);
            $captain->user_id ??= $data['user_id'];
            $captain->save();

            if ($captain->wasRecentlyCreated) {
                $captain->employmentPeriods()->create([
                    'started_at' => $data['hired_at'],
                    'started_by_user_id' => $actor->id,
                ]);
            }

            return $captain;
        });
    }
}
