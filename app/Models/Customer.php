<?php

namespace App\Models;

use App\Services\PhoneNormalizer;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'notes'])]
class Customer extends Model
{
    protected $attributes = ['is_active' => true];

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function addressesAccessibleTo(User $user): HasMany
    {
        $addresses = $this->addresses();
        if ($user->isSuperAdmin()) {
            return $addresses;
        }

        return $addresses->whereHas('area', function (Builder $areas) use ($user): void {
            $areas->whereIn('branch_id', $user->availableBranches()->select('branches.id'));
        });
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where(function (Builder $created) use ($user): void {
                $created->where('created_by_user_id', $user->id)->whereDoesntHave('addresses');
            })->orWhereHas('addresses.area', function (Builder $areas) use ($user): void {
                $areas->whereIn('branch_id', $user->availableBranches()->select('branches.id'));
            });
        });
    }

    public function scopeSearch(Builder $query, ?string $search, PhoneNormalizer $normalizer): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }
        $normalized = $normalizer->normalize($search);

        return $query->where(function (Builder $matches) use ($search, $normalized): void {
            $matches->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
            if ($normalized !== '') {
                $matches->orWhereHas('phones', fn (Builder $phones) => $phones->where('normalized_phone', 'like', "%{$normalized}%"));
            }
        });
    }
}
