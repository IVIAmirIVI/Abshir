<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['is_active' => true];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches');
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()->where('code', Role::SUPER_ADMIN)->exists();
    }

    public function hasPermission(string $code): bool
    {
        return $this->is_active && ($this->isSuperAdmin() || $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('code', $code))->exists());
    }

    public function availableBranches(): Builder
    {
        return Branch::query()->accessibleTo($this);
    }

    public function canAccessBranch(Branch $branch): bool
    {
        return $this->availableBranches()->whereKey($branch->id)->exists();
    }

    public function createdCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'created_by_user_id');
    }

    public function canAccessArea(Area $area): bool
    {
        return Area::query()->accessibleTo($this)->whereKey($area->id)->exists();
    }

    public function canAccessCustomer(Customer $customer): bool
    {
        return Customer::query()->accessibleTo($this)->whereKey($customer->id)->exists();
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
