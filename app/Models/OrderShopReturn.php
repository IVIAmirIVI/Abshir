<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderShopReturn extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime', 'refunded_at' => 'datetime', 'sealed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderShop(): BelongsTo
    {
        return $this->belongsTo(OrderShop::class);
    }

    public function purchaseConfirmation(): BelongsTo
    {
        return $this->belongsTo(OrderShopPurchaseConfirmation::class, 'purchase_confirmation_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(OrderShopReturnEvidence::class);
    }

    public function shortfallAmount(): string
    {
        return (string) BigDecimal::of($this->purchase_amount)->minus($this->refund_amount)->toScale(4);
    }
}
