<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shop_id', 'product_id', 'unit_id', 'currency_id', 'listed_price', 'is_available', 'is_featured'])]
class ShopProduct extends Model
{
    protected $attributes = ['is_available' => true, 'is_featured' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'listed_price' => 'decimal:4',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
