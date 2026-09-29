<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShopReturnEvidence extends Model
{
    protected $table = 'order_shop_return_evidence';

    protected $guarded = [];

    public function orderShopReturn(): BelongsTo
    {
        return $this->belongsTo(OrderShopReturn::class);
    }
}
