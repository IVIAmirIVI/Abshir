<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderBillPaymentEvidence extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderBillPayment::class, 'bill_payment_id');
    }
}
