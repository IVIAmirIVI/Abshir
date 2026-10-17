<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CaptainSelfView;
use App\Support\StaffInbox;
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
        Gate::define(StaffInbox::ABILITY, fn (User $user): bool => StaffInbox::allowed($user));
        Gate::define(CaptainSelfView::ABILITY, fn (User $user): bool => app(CaptainSelfView::class)->resolve($user) !== null);
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }
            // Even a mixed SUPER_ADMIN/CAPTAIN must resolve their own identity here.
            // The existing administrative bypass remains unchanged for other abilities.
            if ($ability === CaptainSelfView::ABILITY) {
                return null;
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
