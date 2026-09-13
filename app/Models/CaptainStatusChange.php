<?php

namespace App\Models;

use App\Enums\CaptainOperationalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['captain_id', 'previous_status', 'new_status', 'reason', 'changed_by_user_id', 'changed_at'])]
class CaptainStatusChange extends Model
{
    protected function casts(): array
    {
        return [
            'previous_status' => CaptainOperationalStatus::class,
            'new_status' => CaptainOperationalStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
