<?php

namespace App\Models;

use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'parent_id', 'name', 'code'])]
class Area extends Model
{
    protected $attributes = ['is_active' => true];

    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('branch_id', $user->availableBranches()->select('branches.id'));
    }

    public function scopeActiveForBranch(Builder $query, Branch $branch): Builder
    {
        return $query->where('branch_id', $branch->id)->where('is_active', true);
    }

    public function path(): string
    {
        $names = [$this->name];
        $area = $this->parent;
        while ($area) {
            array_unshift($names, $area->name);
            $area = $area->parent;
        }

        return implode(' › ', $names);
    }
}
