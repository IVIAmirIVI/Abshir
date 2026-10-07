<?php

namespace App\Models;

use App\Enums\DeliveryPricingStatus;
use App\Enums\OrderPricingClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'version', 'delivery_pricing_rule_id', 'from_area_id', 'to_area_id', 'currency_id', 'order_pricing_class_snapshot', 'route_count_snapshot', 'suggested_unit_fee', 'applied_unit_fee', 'captain_count_snapshot', 'captain_share_percent_snapshot', 'gross_delivery_total', 'discount_amount', 'discount_reason', 'discount_applied_by', 'discount_applied_at', 'customer_delivery_total', 'status', 'priced_at', 'created_by', 'superseded_at', 'superseded_by'])]
class OrderDeliveryPricing extends Model
{
    protected function casts(): array
    {
        return ['order_pricing_class_snapshot' => OrderPricingClass::class, 'route_count_snapshot' => 'integer', 'suggested_unit_fee' => 'decimal:4', 'applied_unit_fee' => 'decimal:4', 'captain_share_percent_snapshot' => 'decimal:4', 'gross_delivery_total' => 'decimal:4', 'discount_amount' => 'decimal:4', 'customer_delivery_total' => 'decimal:4', 'status' => DeliveryPricingStatus::class, 'priced_at' => 'datetime', 'discount_applied_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DeliveryPricingRule::class, 'delivery_pricing_rule_id');
    }

    public function fromArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'from_area_id');
    }

    public function toArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'to_area_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderDeliveryAllocation::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(OrderDeliveryPricingRoute::class);
    }
}
