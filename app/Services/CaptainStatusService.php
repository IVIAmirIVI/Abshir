<?php

namespace App\Services;

use App\Enums\CaptainOperationalStatus;
use App\Models\Captain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaptainStatusService
{
    public function update(User $actor, Captain $captain, CaptainOperationalStatus $status, ?string $reason = null): Captain
    {
        Gate::forUser($actor)->authorize('captains.status.update');
        $reason = filled($reason) ? trim($reason) : null;
        if ($status === CaptainOperationalStatus::Suspended && $reason === null) {
            throw ValidationException::withMessages(['reason' => __('domain.a_reason_is_required_when_suspending_a_captain')]);
        }

        return DB::transaction(function () use ($actor, $captain, $status, $reason): Captain {
            $captain = Captain::query()->lockForUpdate()->findOrFail($captain->id);
            abort_unless($actor->canAccessCaptain($captain), 403);
            $previousStatus = $captain->operational_status;

            if ($previousStatus === $status) {
                return $captain;
            }

            $captain->operational_status = $status;
            $captain->save();
            $captain->statusChanges()->create([
                'previous_status' => $previousStatus,
                'new_status' => $status,
                'reason' => $reason,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $captain;
        });
    }
}
