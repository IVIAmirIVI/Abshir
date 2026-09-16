<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_area_id', 'to_area_id', 'currency_id', 'delivery_unit_fee', 'effective_from', 'effective_until', 'is_active', 'created_by', 'superseded_by', 'notes'])]
class DeliveryPricingRule extends Model
{
    protected function casts(): array
    {
        return ['delivery_unit_fee' => 'decimal:4', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'is_active' => 'boolean'];
    }

    public function fromArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'from_area_id');
    }

    public function toArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'to_area_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    public function scopeEffectiveAt(Builder $query, mixed $at): Builder
    {
        return $query->where('is_active', true)->where('effective_from', '<=', $at)->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at));
    }
}
