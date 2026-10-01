<?php

namespace App\Models;

use App\Models\Concerns\HasImmutableFinancialHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceCorrectionReceivableDisposition extends Model
{
    use HasImmutableFinancialHistory;

    public $timestamps = false;

    protected $guarded = [];

    public function allocations(): HasMany
    {
        return $this->hasMany(InvoiceCorrectionReceivableDispositionAllocation::class, 'disposition_id');
    }
}
