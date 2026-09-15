<?php

namespace App\Models;

use App\Enums\ShopPartnershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['shop_id', 'starts_at', 'ends_at', 'status', 'mobile_enabled', 'online_orders_enabled', 'notes'])]
class ShopPartnership extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => ShopPartnershipStatus::class,
            'mobile_enabled' => 'boolean',
            'online_orders_enabled' => 'boolean',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function scopeActiveAt(Builder $query, Carbon $at): Builder
    {
        return $query->where('status', ShopPartnershipStatus::Active)
            ->where('starts_at', '<=', $at)
            ->where(fn (Builder $dates) => $dates->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
