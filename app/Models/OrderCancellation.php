<?php

namespace App\Models;

use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'classification', 'reason', 'notes', 'cancelled_by', 'cancelled_at'])]
class OrderCancellation extends Model
{
    protected function casts(): array
    {
        return ['classification' => OrderCancellationClassification::class, 'reason' => OrderCancellationReason::class, 'cancelled_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function requiresFinancialResolution(): bool
    {
        return $this->classification->requiresFinancialResolution();
    }
}
