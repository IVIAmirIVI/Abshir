<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['captain_id', 'started_at', 'ended_at', 'end_reason', 'started_by_user_id', 'ended_by_user_id'])]
class CaptainEmploymentPeriod extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function scopeActiveAt(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->where('started_at', '<=', $at)
            ->where(fn (Builder $dates) => $dates->whereNull('ended_at')->orWhere('ended_at', '>', $at));
    }
}
