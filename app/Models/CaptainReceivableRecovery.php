<?php

namespace App\Models;

use App\Models\Concerns\HasImmutableFinancialHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaptainReceivableRecovery extends Model
{
    use HasImmutableFinancialHistory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CaptainReceivableRecoveryAllocation::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(InvoiceFinancialCorrection::class, 'invoice_financial_correction_id');
    }
}
