<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'unit_id', 'version', 'currency_id', 'delivery_fee', 'allocation_mode', 'captain_percentage', 'captain_fixed', 'company_fixed', 'created_by'])]
class ProductDeliveryPolicy extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['delivery_fee' => 'decimal:4', 'captain_percentage' => 'decimal:4', 'captain_fixed' => 'decimal:4', 'company_fixed' => 'decimal:4'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
