<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageUser
{
    public function save(User $actor, User $user, array $data): User
    {
        Gate::forUser($actor)->authorize($user->exists ? 'users.update' : 'users.create');

        return DB::transaction(function () use ($actor, $user, $data) {
            $superRole = Role::where('code', Role::SUPER_ADMIN)->lockForUpdate()->sole();
            if ($user->exists) {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
            }
            abort_if(! $actor->isSuperAdmin() && ($user->is($actor) || ($user->exists && $user->isSuperAdmin())), 403);
            $roles = $data['role_ids'] ?? null;
            $branches = $data['branch_ids'] ?? null;
            if ($roles !== null || $branches !== null) {
                abort_unless($actor->isSuperAdmin(), 403);
                Gate::forUser($actor)->authorize('roles.assign');
            }
            if ($roles !== null && $user->exists && $user->isSuperAdmin()
                && ! in_array($superRole->id, $roles)) {
                $this->guardLastAdministrator($user);
            }
            $user->fill(collect($data)->only(['name', 'email'])->all());
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();
            if ($roles !== null) {
                $user->roles()->sync($roles);
            }
            if ($branches !== null) {
                $user->branches()->sync($branches);
            }

            return $user;
        });
    }

    public function setActive(User $actor, User $user, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'users.activate' : 'users.deactivate');
        DB::transaction(function () use ($actor, $user, $active) {
            Role::where('code', Role::SUPER_ADMIN)->lockForUpdate()->sole();
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if(! $actor->isSuperAdmin() && ($user->is($actor) || $user->isSuperAdmin()), 403);
            if (! $active) {
                $this->guardLastAdministrator($user);
            }
            $user->is_active = $active;
            if (! $active) {
                $user->remember_token = null;
                if (config('session.driver') === 'database') {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
            }
            $user->save();
        });
    }

    private function guardLastAdministrator(User $user): void
    {
        if ($user->is_active && $user->isSuperAdmin()
            && ! User::where('is_active', true)->whereKeyNot($user->id)
                ->whereHas('roles', fn ($query) => $query->where('code', Role::SUPER_ADMIN))->exists()) {
            throw ValidationException::withMessages(['roles' => 'At least one active super administrator must remain.']);
        }
    }
}
