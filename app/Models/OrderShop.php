<?php

namespace App\Models;

use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'shop_type', 'business_classification_snapshot', 'shop_id', 'shop_name_snapshot', 'shop_phone_snapshot', 'shop_address_snapshot', 'area_name_snapshot', 'notes', 'sort_order'])]
class OrderShop extends Model
{
    protected function casts(): array
    {
        return ['shop_type' => OrderShopType::class, 'business_classification_snapshot' => ShopBusinessClassification::class];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }
}
