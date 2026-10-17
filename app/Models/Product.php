<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable(['category_id', 'default_unit_id', 'name', 'sku', 'barcode', 'description', 'is_special'])]
class Product extends Model
{
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_special' => 'boolean'];
    }

    public function deliveryPolicies(): HasMany
    {
        return $this->hasMany(ProductDeliveryPolicy::class);
    }

    public function deliveryPolicy(): HasOne
    {
        return $this->hasOne(ProductDeliveryPolicy::class)->ofMany('version', 'max');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(ProductGroupMember::class);
    }

    public function shopProducts(): HasMany
    {
        return $this->hasMany(ShopProduct::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    public function isMemberOfAt(ProductGroup $group, Carbon $at): bool
    {
        return $this->groupMemberships()->where('product_group_id', $group->id)->activeAt($at)->exists();
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower($search).'%';

        return $query->where(fn (Builder $matches) => $matches
            ->whereRaw('LOWER(name) LIKE ?', [$term])
            ->orWhereRaw('LOWER(sku) LIKE ?', [$term])
            ->orWhereRaw('LOWER(barcode) LIKE ?', [$term]));
    }
}
