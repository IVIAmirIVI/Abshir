<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-authorization', fn (User $user): bool => $user->isSuperAdmin());
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }
            if ($user->isSuperAdmin()) {
                return true;
            }
            if (str_contains($ability, '.')) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }
}
