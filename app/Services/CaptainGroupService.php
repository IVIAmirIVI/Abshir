<?php

namespace App\Services;

use App\Models\CaptainGroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CaptainGroupService
{
    public function save(User $actor, CaptainGroup $group, array $data): CaptainGroup
    {
        Gate::forUser($actor)->authorize($group->exists ? 'captain_groups.update' : 'captain_groups.create');
        $group->fill($data)->save();

        return $group;
    }

    public function setActive(User $actor, CaptainGroup $group, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'captain_groups.activate' : 'captain_groups.deactivate');
        $group->is_active = $active;
        $group->save();
    }
}
