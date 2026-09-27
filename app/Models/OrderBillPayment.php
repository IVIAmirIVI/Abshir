<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderBillPayment extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'principal_amount' => 'decimal:4', 'service_fee' => 'decimal:4', 'total_customer_cash' => 'decimal:4', 'delivered_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OrderBillPaymentRequest::class, 'request_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderCaptain(): BelongsTo
    {
        return $this->belongsTo(OrderCaptain::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function handover(): HasOne
    {
        return $this->hasOne(OrderBillPaymentPrincipalHandover::class, 'bill_payment_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(OrderBillPaymentEvidence::class, 'bill_payment_id');
    }
}
