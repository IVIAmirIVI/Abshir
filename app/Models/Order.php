<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderPricingStatus;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'customer_id', 'customer_phone_id', 'customer_address_id', 'destination_area_id', 'destination_area_confirmed_by', 'destination_area_confirmed_at', 'order_number', 'ordered_at', 'pricing_class', 'customer_name_snapshot', 'customer_phone_snapshot', 'customer_phone_normalized_snapshot', 'address_label_snapshot', 'recipient_name_snapshot', 'area_name_snapshot', 'area_path_snapshot', 'address_line_1_snapshot', 'address_line_2_snapshot', 'landmark_snapshot', 'latitude_snapshot', 'longitude_snapshot', 'notes', 'created_by'])]
class Order extends Model
{
    protected $attributes = ['operational_status' => 'NEW', 'pricing_status' => 'ESTIMATED'];

    protected function casts(): array
    {
        return ['ordered_at' => 'datetime', 'destination_area_confirmed_at' => 'datetime', 'operational_status' => OrderOperationalStatus::class, 'pricing_status' => OrderPricingStatus::class, 'pricing_class' => OrderPricingClass::class, 'latitude_snapshot' => 'decimal:7', 'longitude_snapshot' => 'decimal:7'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerPhone(): BelongsTo
    {
        return $this->belongsTo(CustomerPhone::class);
    }

    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    public function destinationArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'destination_area_id');
    }

    public function deliveryPricings(): HasMany
    {
        return $this->hasMany(OrderDeliveryPricing::class)->orderBy('version');
    }

    public function currentDeliveryPricing(): HasOne
    {
        return $this->hasOne(OrderDeliveryPricing::class)->whereNull('superseded_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('version');
    }

    public function currentInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->whereNull('superseded_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shops(): HasMany
    {
        return $this->hasMany(OrderShop::class)->orderBy('sort_order')->orderBy('id');
    }

    public function captainAssignments(): HasMany
    {
        return $this->hasMany(OrderCaptain::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('changed_at')->orderBy('id');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin() ? $query : $query->whereIn('branch_id', $user->availableBranches()->select('branches.id'));
    }

    public function estimatedItemsTotal(): string
    {
        return $this->sumItems('estimated_unit_price');
    }

    public function actualItemsTotal(): string
    {
        return $this->sumItems('actual_unit_price');
    }

    public function isFinanciallyComplete(): bool
    {
        $invoice = $this->currentInvoice()->with('collection')->first();

        return $invoice?->status === InvoiceStatus::Issued && $invoice->collection !== null;
    }

    private function sumItems(string $column): string
    {
        $total = BigDecimal::zero();
        foreach ($this->shops as $shop) {
            foreach ($shop->items as $item) {
                if ($item->{$column} !== null) {
                    $total = $total->plus(BigDecimal::of($item->{$column})->multipliedBy(BigDecimal::of($item->current_quantity)));
                }
            }
        }

        return (string) $total->toScale(4);
    }
}
