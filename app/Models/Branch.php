<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'address', 'phone', 'default_currency_id'])]
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

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    public function homeCaptains(): HasMany
    {
        return $this->hasMany(Captain::class, 'home_branch_id');
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
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
