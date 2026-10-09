<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderShopPurchaseConfirmation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['purchased_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderShop(): BelongsTo
    {
        return $this->belongsTo(OrderShop::class);
    }

    public function orderCaptain(): BelongsTo
    {
        return $this->belongsTo(OrderCaptain::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderShopPurchaseAllocation::class, 'purchase_confirmation_id')->orderBy('order_captain_id');
    }

    public function collectingOrderCaptain(): BelongsTo
    {
        return $this->belongsTo(OrderCaptain::class, 'collecting_order_captain_id');
    }
}
