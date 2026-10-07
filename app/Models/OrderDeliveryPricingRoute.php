<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_delivery_pricing_id', 'order_shop_id', 'shop_name_snapshot', 'from_area_id', 'to_area_id', 'delivery_pricing_rule_id', 'proposed_unit_fee', 'is_selected_max'])]
class OrderDeliveryPricingRoute extends Model
{
    protected function casts(): array
    {
        return ['proposed_unit_fee' => 'decimal:4', 'is_selected_max' => 'boolean'];
    }

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryPricing::class, 'order_delivery_pricing_id');
    }

    public function fromArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'from_area_id');
    }

    public function toArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'to_area_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DeliveryPricingRule::class, 'delivery_pricing_rule_id');
    }
}
