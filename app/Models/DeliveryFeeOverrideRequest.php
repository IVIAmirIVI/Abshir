<?php

namespace App\Models;

use App\Enums\DeliveryOverrideStatus;
use App\Enums\OrderPricingClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'order_delivery_pricing_id', 'from_area_id', 'order_pricing_class_snapshot', 'suggested_unit_fee', 'requested_unit_fee', 'difference_amount', 'difference_percent', 'reason', 'status', 'requested_by', 'requested_at', 'resolved_by', 'resolved_at', 'resolution_reason'])]
class DeliveryFeeOverrideRequest extends Model
{
    protected function casts(): array
    {
        return ['order_pricing_class_snapshot' => OrderPricingClass::class, 'suggested_unit_fee' => 'decimal:4', 'requested_unit_fee' => 'decimal:4', 'difference_amount' => 'decimal:4', 'difference_percent' => 'decimal:4', 'status' => DeliveryOverrideStatus::class, 'requested_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryPricing::class, 'order_delivery_pricing_id');
    }

    public function fromArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'from_area_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
