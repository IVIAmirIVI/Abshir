<?php

namespace App\Models;

use App\Enums\ShopBusinessClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_classification', 'shop_type_id', 'area_id', 'name', 'code', 'phone', 'address', 'notes', 'is_mobile_visible', 'accepts_online_orders'])]
class Shop extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
        'is_mobile_visible' => false,
        'accepts_online_orders' => false,
    ];

    protected function casts(): array
    {
        return [
            'business_classification' => ShopBusinessClassification::class,
            'is_active' => 'boolean',
            'is_mobile_visible' => 'boolean',
            'accepts_online_orders' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ShopType::class, 'shop_type_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class);
    }

    public function partnerships(): HasMany
    {
        return $this->hasMany(ShopPartnership::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('area', fn (Builder $areas) => $areas
            ->whereIn('branch_id', $user->availableBranches()->select('branches.id')));
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower($search).'%';

        return $query->where(fn (Builder $matches) => $matches
            ->whereRaw('LOWER(name) LIKE ?', [$term])
            ->orWhereRaw('LOWER(code) LIKE ?', [$term]));
    }

    public function hasMobileReadyPartnership(): bool
    {
        return $this->is_active && $this->is_mobile_visible
            && $this->partnerships()->activeAt(now())->where('mobile_enabled', true)->exists();
    }
}
