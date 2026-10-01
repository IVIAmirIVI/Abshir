<?php

namespace App\Models;

use App\Enums\FinancialEntryDirection;
use App\Models\Concerns\HasImmutableFinancialHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptainDepositManualAdjustment extends Model
{
    use HasImmutableFinancialHistory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'direction' => FinancialEntryDirection::class, 'occurred_at' => 'immutable_datetime'];
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by_user_id');
    }
}
