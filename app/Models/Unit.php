<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'symbol', 'allows_decimal'])]
class Unit extends Model
{
    use HasFactory;

    protected $attributes = ['allows_decimal' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['allows_decimal' => 'boolean', 'is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'default_unit_id');
    }

    public function shopProducts(): HasMany
    {
        return $this->hasMany(ShopProduct::class);
    }
}
