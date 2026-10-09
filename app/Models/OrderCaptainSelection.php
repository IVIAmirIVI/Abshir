<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCaptainSelection extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function selectedAt(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => $value === null ? null : $this->asDateTime($value)->format('Y-m-d H:i:sP'),
        );
    }

    protected function casts(): array
    {
        return ['assignment_ids' => 'array', 'selected_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }
}
