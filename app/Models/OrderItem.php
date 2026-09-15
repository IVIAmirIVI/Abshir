<?php

namespace App\Models;

use App\Enums\ActualPriceSource;
use App\Enums\OrderEstimateMethod;
use App\Enums\OrderItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_shop_id', 'item_type', 'product_id', 'product_name_snapshot', 'unit_id', 'unit_name_snapshot', 'unit_symbol_snapshot', 'requested_quantity', 'current_quantity', 'notes', 'estimated_unit_price', 'estimated_price_method', 'estimated_source_shop_id', 'estimated_source_price_observation_id', 'estimated_source_observed_at', 'estimation_window_days', 'estimated_currency_id', 'actual_unit_price', 'actual_price_source', 'actual_currency_id', 'actual_price_confirmed_at', 'actual_price_confirmed_by'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return ['item_type' => OrderItemType::class, 'requested_quantity' => 'decimal:4', 'current_quantity' => 'decimal:4', 'estimated_unit_price' => 'decimal:4', 'estimated_price_method' => OrderEstimateMethod::class, 'estimated_source_observed_at' => 'datetime', 'actual_unit_price' => 'decimal:4', 'actual_price_source' => ActualPriceSource::class, 'actual_price_confirmed_at' => 'datetime'];
    }

    public function orderShop(): BelongsTo
    {
        return $this->belongsTo(OrderShop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function estimatedSourceShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'estimated_source_shop_id');
    }

    public function estimatedSourceObservation(): BelongsTo
    {
        return $this->belongsTo(ProductPriceHistory::class, 'estimated_source_price_observation_id');
    }

    public function estimatedCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'estimated_currency_id');
    }

    public function actualCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'actual_currency_id');
    }

    public function actualPriceConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actual_price_confirmed_by');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(OrderItemChange::class)->orderBy('changed_at')->orderBy('id');
    }
}
