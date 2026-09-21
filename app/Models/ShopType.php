<?php

namespace App\Models;

use App\Enums\ShopBusinessClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }
}
