<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'code', 'address', 'phone'])]
class Branch extends Model
{
    protected $attributes = ['is_active' => true];

    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branches');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where('is_active', true)
            ->whereHas('users', fn (Builder $users) => $users->whereKey($user->id));
    }
}
