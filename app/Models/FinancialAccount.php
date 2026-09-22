<?php

namespace App\Models;

use App\Enums\FinancialAccountKind;
use App\Models\Concerns\HasImmutableFinancialHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    use HasImmutableFinancialHistory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['account_kind' => FinancialAccountKind::class];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(FinancialOwner::class, 'financial_owner_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }
}
