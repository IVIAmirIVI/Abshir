<?php

namespace App\Models;

use App\Enums\OrderOperationalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['order_id', 'previous_status', 'new_status', 'changed_by', 'changed_at', 'reason'])]
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected function casts(): array
    {
        return ['previous_status' => OrderOperationalStatus::class, 'new_status' => OrderOperationalStatus::class, 'changed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order status history is immutable.'));
        static::deleting(fn () => throw new LogicException('Order status history cannot be deleted.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
