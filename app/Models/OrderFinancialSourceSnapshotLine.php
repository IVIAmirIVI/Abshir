<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_captain_id', 'captain_id', 'is_primary', 'product_funding_share', 'captain_entitlement', 'customer_delivery_amount_allocated', 'company_collectible', 'captain_shortfall'])]
class OrderFinancialSourceSnapshotLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'product_funding_share' => 'decimal:4',
            'captain_entitlement' => 'decimal:4',
            'customer_delivery_amount_allocated' => 'decimal:4',
            'company_collectible' => 'decimal:4',
            'captain_shortfall' => 'decimal:4',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderFinancialSourceSnapshot::class, 'order_financial_source_snapshot_id');
    }
}
