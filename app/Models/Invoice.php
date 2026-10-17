<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['order_id', 'invoice_number', 'version', 'status', 'currency_id', 'source_order_delivery_pricing_id', 'source_items_fingerprint', 'items_subtotal', 'delivery_total', 'grand_total', 'customer_name_snapshot', 'customer_phone_snapshot', 'address_snapshot', 'area_snapshot', 'order_number_snapshot', 'ordered_at_snapshot', 'ready_at', 'issued_at', 'issued_by', 'voided_at', 'voided_by', 'void_reason', 'replaced_at', 'replaced_by', 'replacement_reason', 'replaced_by_invoice_id', 'superseded_at', 'created_by'])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return ['status' => InvoiceStatus::class, 'items_subtotal' => 'decimal:4', 'delivery_total' => 'decimal:4', 'grand_total' => 'decimal:4', 'ordered_at_snapshot' => 'datetime', 'ready_at' => 'datetime', 'issued_at' => 'datetime', 'voided_at' => 'datetime', 'replaced_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function sourceDeliveryPricing(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryPricing::class, 'source_order_delivery_pricing_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function collection(): HasOne
    {
        return $this->hasOne(InvoiceCollection::class);
    }

    public function settledCorrection(): HasOne
    {
        return $this->hasOne(InvoiceFinancialCorrection::class, 'replacement_invoice_id')->where('state', 'COMPLETED');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }

    public function replacementInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_invoice_id');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('order', fn (Builder $orders) => $orders->accessibleTo($user));
    }
}
