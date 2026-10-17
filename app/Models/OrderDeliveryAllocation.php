<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_delivery_pricing_id', 'order_captain_id', 'captain_id', 'currency_id', 'applied_unit_fee', 'captain_share_percent_snapshot', 'captain_entitlement', 'discount_allocated', 'customer_delivery_amount_allocated', 'company_collectible', 'captain_shortfall', 'base_captain_share_percent_snapshot', 'fuel_allowance_percent_snapshot', 'base_captain_entitlement', 'fuel_allowance_amount'])]
class OrderDeliveryAllocation extends Model
{
    protected function casts(): array
    {
        return ['applied_unit_fee' => 'decimal:4', 'captain_share_percent_snapshot' => 'decimal:4', 'captain_entitlement' => 'decimal:4', 'discount_allocated' => 'decimal:4', 'customer_delivery_amount_allocated' => 'decimal:4', 'company_collectible' => 'decimal:4', 'captain_shortfall' => 'decimal:4', 'base_captain_share_percent_snapshot' => 'decimal:4', 'fuel_allowance_percent_snapshot' => 'decimal:4', 'base_captain_entitlement' => 'decimal:4', 'fuel_allowance_amount' => 'decimal:4'];
    }

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryPricing::class, 'order_delivery_pricing_id');
    }

    public function orderCaptain(): BelongsTo
    {
        return $this->belongsTo(OrderCaptain::class);
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
