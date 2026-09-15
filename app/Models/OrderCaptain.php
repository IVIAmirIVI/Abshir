<?php

namespace App\Models;

use App\Enums\OrderCaptainRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'captain_id', 'assignment_role', 'assigned_at', 'assigned_by', 'removed_at', 'removed_by', 'removal_reason'])]
class OrderCaptain extends Model
{
    protected function casts(): array
    {
        return ['assignment_role' => OrderCaptainRole::class, 'assigned_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
