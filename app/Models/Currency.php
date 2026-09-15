<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'symbol', 'decimal_places'])]
class Currency extends Model
{
    use HasFactory;

    protected $attributes = ['decimal_places' => 2, 'is_active' => true];

    protected function casts(): array
    {
        return ['decimal_places' => 'integer', 'is_active' => 'boolean'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'default_currency_id');
    }
}
