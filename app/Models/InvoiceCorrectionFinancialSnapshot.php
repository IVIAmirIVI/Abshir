<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceCorrectionFinancialSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'captured_at' => 'datetime'];
    }
}
