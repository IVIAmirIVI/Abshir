<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductGroupMembershipService
{
    public function join(User $actor, Product $product, ProductGroup $group, array $data): ProductGroupMember
    {
        Gate::forUser($actor)->authorize('product_groups.assign');
        if (! $group->is_active) {
            throw ValidationException::withMessages(['product_group_id' => __('domain.an_inactive_product_group_cannot_receive_new_memberships')]);
        }

        return DB::transaction(function () use ($actor, $product, $group, $data): ProductGroupMember {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $joinedAt = Carbon::parse($data['joined_at']);
            $leftAt = isset($data['left_at']) ? Carbon::parse($data['left_at']) : null;
            $overlap = $product->groupMemberships()->where('product_group_id', $group->id)
                ->where(fn (Builder $existing) => $existing->whereNull('left_at')->orWhere('left_at', '>', $joinedAt));
            if ($leftAt) {
                $overlap->where('joined_at', '<', $leftAt);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['joined_at' => __('domain.this_membership_overlaps_an_existing_membership_in_the_product_group')]);
            }

            $membership = $product->groupMemberships()->make([
                'product_group_id' => $group->id,
                'joined_at' => $joinedAt,
                'left_at' => $leftAt,
            ]);
            $membership->created_by_user_id = $actor->id;
            $membership->save();

            return $membership;
        });
    }

    public function end(User $actor, Product $product, ProductGroupMember $membership): void
    {
        Gate::forUser($actor)->authorize('product_groups.assign');
        abort_if($membership->product_id !== $product->id, 404);

        DB::transaction(function () use ($actor, $membership): void {
            $membership = ProductGroupMember::query()->lockForUpdate()->findOrFail($membership->id);
            if (! $membership->isActiveAt()) {
                throw ValidationException::withMessages(['membership' => __('domain.only_a_current_membership_can_be_ended')]);
            }
            $membership->left_at = now();
            $membership->ended_by_user_id = $actor->id;
            $membership->save();
        });
    }
}
