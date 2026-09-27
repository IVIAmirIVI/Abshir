<?php

namespace App\Models;

use App\Enums\OrderBillPaymentRequestStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class OrderBillPaymentRequest extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected function requestedAt(): Attribute
    {
        return Attribute::make(set: fn ($value): string => Carbon::parse($value)->format('Y-m-d H:i:s.uP'));
    }

    protected function casts(): array
    {
        return ['status' => OrderBillPaymentRequestStatus::class, 'service_fee' => 'decimal:4', 'requested_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(BillPaymentFeeRule::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(OrderBillPayment::class, 'request_id');
    }
}
