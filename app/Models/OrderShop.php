<?php

namespace App\Models;

use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'shop_type', 'business_classification_snapshot', 'shop_id', 'shop_name_snapshot', 'shop_phone_snapshot', 'shop_address_snapshot', 'area_name_snapshot', 'notes', 'sort_order', 'contacted_at', 'confirmed_at', 'ready_at', 'picked_up_at'])]
class OrderShop extends Model
{
    protected function casts(): array
    {
        return ['shop_type' => OrderShopType::class, 'business_classification_snapshot' => ShopBusinessClassification::class, 'contacted_at' => 'datetime', 'confirmed_at' => 'datetime', 'ready_at' => 'datetime', 'picked_up_at' => 'datetime'];
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

    public function receipts(): HasMany
    {
        return $this->hasMany(OrderShopReceipt::class)->orderBy('uploaded_at')->orderBy('id');
    }
}
