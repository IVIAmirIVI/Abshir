<?php

namespace App\Models;

use App\Enums\CaptainAssignmentType;
use Database\Factories\CaptainBranchAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['captain_id', 'assignment_type', 'from_branch_id', 'branch_id', 'starts_at', 'ends_at', 'reason'])]
class CaptainBranchAssignment extends Model
{
    /** @use HasFactory<CaptainBranchAssignmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'assignment_type' => CaptainAssignmentType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function scopeTemporary(Builder $query): Builder
    {
        return $query->where('assignment_type', CaptainAssignmentType::Temporary->value);
    }

    public function scopeActiveAt(Builder $query, Carbon $at): Builder
    {
        return $query->where('starts_at', '<=', $at)
            ->where(fn (Builder $dates) => $dates->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function isActiveAt(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->assignment_type === CaptainAssignmentType::Temporary
            && $this->starts_at->lte($at)
            && (! $this->ends_at || $this->ends_at->gt($at));
    }
}
