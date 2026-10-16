<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReturnCase extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'sealed_at' => 'immutable_datetime'];
    }

    public function responsibilities(): HasMany
    {
        return $this->hasMany(CustomerReturnResponsibility::class, 'case_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CustomerReturnMovement::class, 'case_id');
    }
}
