<?php

namespace App\Models;

use App\Enums\InvoiceCollectionMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'order_id', 'captain_id', 'order_captain_id', 'collection_method', 'payment_reference', 'currency_id', 'amount', 'collected_at', 'recorded_by', 'notes'])]
class InvoiceCollection extends Model
{
    protected function casts(): array
    {
        return ['collection_method' => InvoiceCollectionMethod::class, 'amount' => 'decimal:4', 'collected_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('order', fn (Builder $orders) => $orders->accessibleTo($user));
    }
}
