<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShopPurchaseAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function purchaseConfirmation(): BelongsTo
    {
        return $this->belongsTo(OrderShopPurchaseConfirmation::class, 'purchase_confirmation_id');
    }

    public function orderCaptain(): BelongsTo
    {
        return $this->belongsTo(OrderCaptain::class);
    }
}
