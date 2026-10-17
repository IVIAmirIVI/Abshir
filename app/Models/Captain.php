<?php

namespace App\Models;

use App\Enums\CaptainAssignmentType;
use App\Enums\CaptainOperationalStatus;
use Database\Factories\CaptainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Fillable(['user_id', 'home_branch_id', 'operational_status', 'employee_code', 'notes', 'fuel_allowance_percentage'])]
class Captain extends Model
{
    /** @use HasFactory<CaptainFactory> */
    use HasFactory;

    protected $attributes = ['operational_status' => CaptainOperationalStatus::Unavailable->value];

    protected function casts(): array
    {
        return [
            'operational_status' => CaptainOperationalStatus::class,
            'fuel_allowance_percentage' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(CaptainStatusChange::class);
    }

    public function employmentPeriods(): HasMany
    {
        return $this->hasMany(CaptainEmploymentPeriod::class);
    }

    public function currentEmploymentPeriod(): ?CaptainEmploymentPeriod
    {
        return $this->employmentPeriods()->whereNull('ended_at')->latest('started_at')->first();
    }

    public function isEmployed(): bool
    {
        return $this->employmentPeriods()->whereNull('ended_at')->exists();
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(CaptainBranchAssignment::class);
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(CaptainGroupMember::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(CaptainRating::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user, ?Carbon $at = null): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }
        $at ??= now();
        $branchIds = $user->availableBranches()->select('branches.id');

        return $query->where(function (Builder $visible) use ($branchIds, $at): void {
            $visible->whereIn('home_branch_id', clone $branchIds)
                ->orWhereHas('branchAssignments', function (Builder $assignments) use ($branchIds, $at): void {
                    $assignments->where('assignment_type', CaptainAssignmentType::Temporary->value)
                        ->where('starts_at', '<=', $at)
                        ->where(fn (Builder $dates) => $dates->whereNull('ends_at')->orWhere('ends_at', '>', $at))
                        ->whereIn('branch_id', clone $branchIds);
                });
        });
    }

    public function activeOperationalBranches(?Carbon $at = null): Collection
    {
        $at ??= now();
        $temporaryIds = $this->branchAssignments()->temporary()->activeAt($at)->pluck('branch_id');

        return Branch::query()->whereKey($temporaryIds->push($this->home_branch_id)->unique())->orderBy('name')->get();
    }

    public function isMemberOfAt(CaptainGroup $group, Carbon $at): bool
    {
        return $this->groupMemberships()->where('captain_group_id', $group->id)->activeAt($at)->exists();
    }
}
