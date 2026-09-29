<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCancellationIntentShop extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function intent(): BelongsTo
    {
        return $this->belongsTo(OrderCancellationIntent::class, 'order_cancellation_intent_id');
    }

    public function purchaseConfirmation(): BelongsTo
    {
        return $this->belongsTo(OrderShopPurchaseConfirmation::class, 'purchase_confirmation_id');
    }
}
