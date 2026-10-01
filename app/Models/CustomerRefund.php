<?php

namespace App\Models;

use App\Enums\InvoiceCollectionMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRefund extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['method' => InvoiceCollectionMethod::class, 'amount' => 'decimal:4', 'refunded_at' => 'datetime'];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(InvoiceFinancialCorrection::class, 'invoice_financial_correction_id');
    }
}
