<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopPartnership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use stdClass;

class ShopCommissionActivationService
{
    public function change(User $actor, Shop $shop, ShopPartnership $partnership, int $draftId, bool $active, string $reason): stdClass
    {
        app(ShopCommissionDraftService::class)->assertAccess($actor, $shop, $partnership);
        Gate::forUser($actor)->authorize('shop_partnerships.update');
        Gate::forUser($actor)->authorize('currencies.view');
        if (! Schema::hasTable('shop_commission_activation_events')) {
            throw ValidationException::withMessages(['commission' => __('commissions.activation_required')]);
        }
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => __('commissions.reason_required')]);
        }
        try {
            return DB::transaction(function () use ($actor, $shop, $partnership, $draftId, $active, $reason): stdClass {
                DB::select('SELECT pg_advisory_xact_lock(161010, CAST(? AS integer))', [$shop->id]);
                $partnership = ShopPartnership::query()->lockForUpdate()->findOrFail($partnership->id);
                app(ShopCommissionDraftService::class)->assertAccess($actor, $shop, $partnership);
                $draft = $partnership->commissionDrafts()->findOrFail($draftId);
                if ($active && $draft->product_id) {
                    app(ShopCommissionDraftService::class)->assertProductScope($actor, $shop, Product::findOrFail($draft->product_id), true);
                }
                $prior = DB::table('shop_commission_activation_events')->where('configuration_key', $draft->configuration_key)->orderByDesc('id')->first();
                $state = $active ? 'ACTIVE' : 'INACTIVE';
                if ($prior && $prior->draft_id === $draft->id && $prior->state === $state) {
                    return $prior;
                }
                $id = DB::table('shop_commission_activation_events')->insertGetId([
                    'draft_id' => $draft->id, 'configuration_key' => $draft->configuration_key, 'previous_id' => $prior?->id,
                    'state' => $state, 'recorded_by' => $actor->id, 'reason' => trim($reason),
                ]);

                return DB::table('shop_commission_activation_events')->find($id);
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23514') {
                throw $exception;
            }
            throw ValidationException::withMessages(['commission' => __('commissions.activation_invalid')]);
        }
    }
}
