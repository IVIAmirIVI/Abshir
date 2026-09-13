<?php

namespace App\Models;

use Database\Factories\CustomerPhoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['phone', 'normalized_phone', 'label'])]
class CustomerPhone extends Model
{
    protected $attributes = ['is_primary' => false];

    /** @use HasFactory<CustomerPhoneFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
