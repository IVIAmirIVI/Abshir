<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
