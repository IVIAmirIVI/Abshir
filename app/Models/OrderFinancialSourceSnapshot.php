<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['invoice_id', 'invoice_version', 'order_id', 'branch_id', 'issued_at', 'order_delivery_pricing_id', 'currency_id', 'currency_decimal_places', 'fingerprint_version', 'primary_order_captain_id', 'items_subtotal', 'payload_hash', 'captured_at'])]
class OrderFinancialSourceSnapshot extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['items_subtotal' => 'decimal:4', 'currency_decimal_places' => 'integer', 'fingerprint_version' => 'integer', 'captured_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderFinancialSourceSnapshotLine::class);
    }
}
