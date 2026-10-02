<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AreaService
{
    public function save(User $actor, Area $area, array $data): Area
    {
        Gate::forUser($actor)->authorize($area->exists ? 'areas.update' : 'areas.create');

        return DB::transaction(function () use ($actor, $area, $data): Area {
            if ($area->exists) {
                $area = Area::query()->lockForUpdate()->findOrFail($area->id);
                abort_unless($actor->canAccessArea($area), 403);
            }
            $branch = Branch::query()->findOrFail($data['branch_id']);
            abort_unless($actor->canAccessBranch($branch), 403);

            if ($area->exists && $area->branch_id !== $branch->id && ($area->children()->exists() || $area->addresses()->exists())) {
                throw ValidationException::withMessages(['branch_id' => __('domain.an_area_with_children_or_customer_addresses_cannot_be_moved_to_another_branch')]);
            }

            $parent = isset($data['parent_id']) ? Area::query()->lockForUpdate()->findOrFail($data['parent_id']) : null;
            $this->validateParent($actor, $area, $branch, $parent);

            $area->fill($data);
            $area->save();

            return $area;
        });
    }

    public function setActive(User $actor, Area $area, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'areas.activate' : 'areas.deactivate');
        abort_unless($actor->canAccessArea($area), 403);
        $area->is_active = $active;
        $area->save();
    }

    public function validParents(User $actor, Branch $branch, ?Area $area = null): Collection
    {
        abort_unless($actor->canAccessBranch($branch), 403);
        $excluded = $area ? $this->descendantIds($area)->push($area->id) : collect();

        return Area::query()->activeForBranch($branch)->whereNotIn('id', $excluded)->orderBy('name')->get();
    }

    private function validateParent(User $actor, Area $area, Branch $branch, ?Area $parent): void
    {
        if (! $parent) {
            return;
        }
        abort_unless($actor->canAccessArea($parent), 403);
        if ($parent->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['parent_id' => __('domain.the_parent_area_must_belong_to_the_selected_branch')]);
        }
        if (! $parent->is_active && $area->parent_id !== $parent->id) {
            throw ValidationException::withMessages(['parent_id' => __('domain.the_selected_parent_area_is_inactive')]);
        }
        if ($area->exists && ($parent->id === $area->id || $this->descendantIds($area)->contains($parent->id))) {
            throw ValidationException::withMessages(['parent_id' => __('domain.the_selected_parent_would_create_an_area_hierarchy_cycle')]);
        }
    }

    private function descendantIds(Area $area): Collection
    {
        $ids = collect();
        $pending = collect([$area->id]);
        while ($pending->isNotEmpty()) {
            $children = Area::query()->whereIn('parent_id', $pending)->pluck('id');
            $ids = $ids->merge($children);
            $pending = $children;
        }

        return $ids;
    }
}
