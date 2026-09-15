<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function save(User $actor, Category $category, array $data): Category
    {
        Gate::forUser($actor)->authorize($category->exists ? 'categories.update' : 'categories.create');

        return DB::transaction(function () use ($category, $data): Category {
            if ($category->exists) {
                $category = Category::query()->lockForUpdate()->findOrFail($category->id);
            }
            $parent = isset($data['parent_id']) ? Category::query()->lockForUpdate()->findOrFail($data['parent_id']) : null;
            if ($parent && ! $parent->is_active && $category->parent_id !== $parent->id) {
                throw ValidationException::withMessages(['parent_id' => 'An inactive category cannot be selected as a new parent.']);
            }
            if ($parent && $category->exists && ($parent->is($category) || $this->descendantIds($category)->contains($parent->id))) {
                throw ValidationException::withMessages(['parent_id' => 'The selected parent would create a category hierarchy cycle.']);
            }

            $category->fill($data)->save();

            return $category;
        });
    }

    public function setActive(User $actor, Category $category, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'categories.activate' : 'categories.deactivate');
        $category->is_active = $active;
        $category->save();
    }

    public function validParents(?Category $category = null): Collection
    {
        $excluded = $category ? $this->descendantIds($category)->push($category->id) : collect();

        return Category::query()->where('is_active', true)->whereNotIn('id', $excluded)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    private function descendantIds(Category $category): Collection
    {
        $ids = collect();
        $pending = collect([$category->id]);
        while ($pending->isNotEmpty()) {
            $children = Category::query()->whereIn('parent_id', $pending)->pluck('id');
            $ids = $ids->merge($children);
            $pending = $children;
        }

        return $ids;
    }
}
