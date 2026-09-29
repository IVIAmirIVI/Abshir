<?php

namespace App\Models;

use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderCancellationIntent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['classification' => OrderCancellationClassification::class, 'reason' => OrderCancellationReason::class,
            'intent_at' => 'datetime', 'frozen_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requiredShops(): HasMany
    {
        return $this->hasMany(OrderCancellationIntentShop::class, 'order_cancellation_intent_id');
    }
}
