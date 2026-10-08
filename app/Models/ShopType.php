<?php

namespace App\Models;

use App\Enums\ShopBusinessClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'description', 'business_classification'])]
class ShopType extends Model
{
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'business_classification' => ShopBusinessClassification::class];
    }

    /** Shared source for optional types in normal Shop administration and Order entry. */
    public static function selectable(?int $currentId = null): Collection
    {
        return static::query()->where(fn ($query) => $query->where('is_active', true)->when($currentId, fn ($q) => $q->orWhereKey($currentId)))->orderBy('name')->get();
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }
}
