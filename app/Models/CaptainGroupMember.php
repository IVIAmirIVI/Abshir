<?php

namespace App\Models;

use Database\Factories\CaptainGroupMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['captain_id', 'captain_group_id', 'joined_at', 'left_at'])]
class CaptainGroupMember extends Model
{
    /** @use HasFactory<CaptainGroupMemberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CaptainGroup::class, 'captain_group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function scopeActiveAt(Builder $query, Carbon $at): Builder
    {
        return $query->where('joined_at', '<=', $at)
            ->where(fn (Builder $dates) => $dates->whereNull('left_at')->orWhere('left_at', '>', $at));
    }

    public function isActiveAt(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->joined_at->lte($at) && (! $this->left_at || $this->left_at->gt($at));
    }
}
