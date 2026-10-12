<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['configuration_key', 'version', 'replaces_id', 'shop_partnership_id', 'product_id', 'product_group_id', 'currency_id', 'target_name_snapshot', 'currency_code_snapshot', 'terms', 'created_by_user_id', 'recorded_at', 'method', 'commission_value', 'group_priority', 'effective_from', 'effective_until'])]
class ShopCommissionDraft extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['version' => 'integer', 'recorded_at' => 'datetime', 'group_priority' => 'integer', 'effective_from' => 'datetime', 'effective_until' => 'datetime'];
    }

    public function partnership(): BelongsTo
    {
        return $this->belongsTo(ShopPartnership::class, 'shop_partnership_id');
    }

    public function nextVersion(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
