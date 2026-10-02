<?php

namespace App\Services;

use App\Enums\ShopPartnershipStatus;
use App\Models\Shop;
use App\Models\ShopPartnership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShopPartnershipService
{
    public function start(User $actor, Shop $shop, array $data): ShopPartnership
    {
        Gate::forUser($actor)->authorize('shop_partnerships.create');
        abort_unless($actor->canAccessShop($shop), 403);

        return DB::transaction(function () use ($actor, $shop, $data): ShopPartnership {
            Shop::query()->lockForUpdate()->findOrFail($shop->id);
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;
            $overlap = $shop->partnerships()
                ->where(fn (Builder $existing) => $existing->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt));
            if ($endsAt) {
                $overlap->where('starts_at', '<', $endsAt);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['starts_at' => __('domain.this_partnership_overlaps_an_existing_partnership_period')]);
            }

            $partnership = $shop->partnerships()->make($data);
            $partnership->created_by_user_id = $actor->id;
            $partnership->save();

            return $partnership;
        });
    }

    public function end(User $actor, Shop $shop, ShopPartnership $partnership): void
    {
        Gate::forUser($actor)->authorize('shop_partnerships.update');
        abort_unless($actor->canAccessShop($shop), 403);
        abort_if($partnership->shop_id !== $shop->id, 404);

        DB::transaction(function () use ($actor, $partnership): void {
            $partnership = ShopPartnership::query()->lockForUpdate()->findOrFail($partnership->id);
            if ($partnership->ends_at) {
                throw ValidationException::withMessages(['partnership' => __('domain.this_partnership_has_already_ended')]);
            }
            $endedAt = now();
            if ($endedAt->lte($partnership->starts_at)) {
                throw ValidationException::withMessages(['partnership' => __('domain.a_partnership_cannot_end_before_it_starts')]);
            }
            $partnership->ends_at = $endedAt;
            $partnership->status = ShopPartnershipStatus::Ended;
            $partnership->ended_by_user_id = $actor->id;
            $partnership->save();
        });
    }
}
