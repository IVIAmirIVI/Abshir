<?php

namespace App\Models;

use App\Enums\PriceObservationSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['product_id', 'shop_id', 'unit_id', 'currency_id', 'price', 'observed_at', 'source', 'notes', 'recorded_by'])]
class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    /** Preserve the absolute instant independently of PostgreSQL's session timezone. */
    protected function observedAt(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => $value === null ? null : $this->asDateTime($value)->format('Y-m-d H:i:sP'),
        );
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'observed_at' => 'datetime',
            'source' => PriceObservationSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Historical price observations are append-only.'));
        static::deleting(fn () => throw new LogicException('Historical price observations cannot be deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('shop', fn (Builder $shops) => $shops->accessibleTo($user));
    }
}
