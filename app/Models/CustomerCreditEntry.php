<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCreditEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
