<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['currency_id', 'fixed_fee', 'fee_mode', 'fee_tiers', 'effective_from', 'effective_until', 'superseded_by', 'created_by'])]
class BillPaymentFeeRule extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['fixed_fee' => 'decimal:4', 'fee_tiers' => 'array', 'effective_from' => 'datetime', 'effective_until' => 'datetime'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
