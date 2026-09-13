<?php

namespace App\Services;

use App\Enums\CaptainOperationalStatus;
use App\Models\Captain;
use App\Models\CaptainEmploymentPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaptainEmploymentService
{
    public function terminate(User $actor, Captain $captain, ?string $endedAt, string $reason): CaptainEmploymentPeriod
    {
        Gate::forUser($actor)->authorize('captains.update');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A termination reason is required.']);
        }

        return DB::transaction(function () use ($actor, $captain, $endedAt, $reason): CaptainEmploymentPeriod {
            $captain = Captain::query()->lockForUpdate()->findOrFail($captain->id);
            abort_unless($actor->canAccessCaptain($captain), 403);
            $period = $captain->employmentPeriods()->whereNull('ended_at')->lockForUpdate()->first();

            if (! $period) {
                throw ValidationException::withMessages(['employment' => 'This Captain is not currently employed.']);
            }

            $effectiveAt = $endedAt ? Carbon::parse($endedAt) : now();
            if ($effectiveAt->lt($period->started_at)) {
                throw ValidationException::withMessages(['ended_at' => 'The termination time cannot be before employment started.']);
            }

            $period->update([
                'ended_at' => $effectiveAt,
                'end_reason' => $reason,
                'ended_by_user_id' => $actor->id,
            ]);

            $this->setUnavailableForEmploymentChange($actor, $captain, $reason);
            $captain->user()->update(['is_active' => false]);

            return $period;
        });
    }

    public function rehire(User $actor, Captain $captain, string $startedAt, bool $reactivateAccount): CaptainEmploymentPeriod
    {
        Gate::forUser($actor)->authorize('captains.update');
        if ($reactivateAccount) {
            Gate::forUser($actor)->authorize('users.activate');
        }

        return DB::transaction(function () use ($actor, $captain, $startedAt, $reactivateAccount): CaptainEmploymentPeriod {
            $captain = Captain::query()->lockForUpdate()->findOrFail($captain->id);
            abort_unless($actor->canAccessCaptain($captain), 403);

            if ($captain->employmentPeriods()->whereNull('ended_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['employment' => 'This Captain already has an open employment period.']);
            }

            $effectiveAt = Carbon::parse($startedAt);
            $latestEnd = $captain->employmentPeriods()->max('ended_at');
            if ($latestEnd && $effectiveAt->lt(Carbon::parse($latestEnd))) {
                throw ValidationException::withMessages(['started_at' => 'Rehire cannot overlap a previous employment period.']);
            }

            $period = $captain->employmentPeriods()->create([
                'started_at' => $effectiveAt,
                'started_by_user_id' => $actor->id,
            ]);

            if ($reactivateAccount) {
                $captain->user()->update(['is_active' => true]);
            }

            return $period;
        });
    }

    private function setUnavailableForEmploymentChange(User $actor, Captain $captain, string $reason): void
    {
        $previousStatus = $captain->operational_status;
        if ($previousStatus === CaptainOperationalStatus::Unavailable) {
            return;
        }

        $captain->update(['operational_status' => CaptainOperationalStatus::Unavailable]);
        $captain->statusChanges()->create([
            'previous_status' => $previousStatus,
            'new_status' => CaptainOperationalStatus::Unavailable,
            'reason' => 'Employment terminated: '.$reason,
            'changed_by_user_id' => $actor->id,
            'changed_at' => now(),
        ]);
    }
}
