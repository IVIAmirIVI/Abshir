<?php

namespace App\Models;

use App\Enums\OrderItemChangeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['order_item_id', 'change_type', 'previous_values', 'new_values', 'changed_by', 'changed_at', 'reason'])]
class OrderItemChange extends Model
{
    protected function casts(): array
    {
        return ['change_type' => OrderItemChangeType::class, 'previous_values' => 'array', 'new_values' => 'array', 'changed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order item change history is immutable.'));
        static::deleting(fn () => throw new LogicException('Order item change history cannot be deleted.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
