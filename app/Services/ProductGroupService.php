<?php

namespace App\Services;

use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ProductGroupService
{
    public function save(User $actor, ProductGroup $group, array $data): ProductGroup
    {
        Gate::forUser($actor)->authorize($group->exists ? 'product_groups.update' : 'product_groups.create');
        $group->fill($data)->save();

        return $group;
    }

    public function setActive(User $actor, ProductGroup $group, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'product_groups.activate' : 'product_groups.deactivate');
        $group->is_active = $active;
        $group->save();
    }
}
