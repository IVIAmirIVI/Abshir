<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReturnResponsibility extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
