<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_shop_id', 'storage_path', 'original_filename', 'mime_type', 'file_size', 'uploaded_by', 'uploaded_at', 'note'])]
class OrderShopReceipt extends Model
{
    protected function casts(): array
    {
        return ['file_size' => 'integer', 'uploaded_at' => 'datetime'];
    }

    public function orderShop(): BelongsTo
    {
        return $this->belongsTo(OrderShop::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
