<?php

namespace App\Models;

use App\Enums\CaptainRatingSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'captain_id', 'order_captain_id', 'rating', 'source', 'rated_at', 'entered_by'])]
class CaptainRating extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer', 'source' => CaptainRatingSource::class, 'rated_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function orderCaptain(): BelongsTo
    {
        return $this->belongsTo(OrderCaptain::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('order', fn (Builder $orders) => $orders->accessibleTo($user));
    }
}
