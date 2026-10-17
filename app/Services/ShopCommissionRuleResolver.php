<?php

namespace App\Services;

use App\Enums\ShopPartnershipStatus;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopCommissionDraft;
use App\Models\ShopPartnership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ShopCommissionRuleResolver
{
    /** Internal financial resolution; authorized issuance supplies persisted source identities. */
    public function resolveActive(?int $shopId, ?int $productId, int $currencyId, Carbon $at): ?ShopCommissionDraft
    {
        if (! $shopId || ! Schema::hasTable('shop_commission_activation_events')) {
            return null;
        }
        $id = DB::scalar('SELECT resolve_active_shop_commission(?, ?, ?, CAST(? AS timestamptz))', [$shopId, $productId, $currencyId, $at->format('Y-m-d H:i:s.uP')]);

        return $id ? ShopCommissionDraft::findOrFail($id) : null;
    }

    /** Resolve only from persisted item ownership; the caller must explicitly choose the preview time. */
    public function previewItem(User $actor, OrderItem $item, Carbon $at, ?Carbon $knownAt = null): array
    {
        Gate::forUser($actor)->authorize('orders.view');
        $item = $item->fresh();
        $orderShop = $item->orderShop;
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($orderShop->order_id)->exists(), 403);
        $shop = $orderShop->shop;
        if (! $shop) {
            return $this->result('NONE', null, 'no_partner_shop');
        }
        Gate::forUser($actor)->authorize('shops.view');
        Gate::forUser($actor)->authorize('shop_partnerships.view');
        abort_unless($actor->canAccessShop($shop), 403);
        $instant = $at->format('Y-m-d H:i:s.uP');
        $partnerships = $shop->partnerships()->where('starts_at', '<=', $instant)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $instant))->limit(2)->get();
        if ($partnerships->count() > 1) {
            return $this->result('CONFLICT', null, 'ambiguous');
        }
        $currency = $item->actual_unit_price !== null ? $item->actualCurrency : $item->estimatedCurrency;
        if (! $partnerships->first() || ! $currency) {
            return $this->result('NONE', null, 'no_match');
        }

        return $this->preview($actor, $shop, $partnerships->first(), $item->product, $currency, $at, $knownAt);
    }

    /**
     * Match configuration only, not activation or a monetary estimate.
     * $at is agreement/membership time; $knownAt is the historical audit cutoff.
     *
     * @return array{status: string, rule: ?ShopCommissionDraft, reason: string}
     */
    public function preview(User $actor, Shop $shop, ShopPartnership $partnership, ?Product $product, Currency $currency, Carbon $at, ?Carbon $knownAt = null): array
    {
        app(ShopCommissionDraftService::class)->assertAccess($actor, $shop, $partnership);
        if ($product) {
            Gate::forUser($actor)->authorize('products.view');
        }
        Gate::forUser($actor)->authorize('currencies.view');
        if (! Schema::hasColumn('shop_commission_drafts', 'method')) {
            return $this->result('UNAVAILABLE', null, 'activation_required');
        }
        $partnership = $partnership->fresh();
        if ($partnership->status === ShopPartnershipStatus::Suspended || $at->lt($partnership->starts_at)
            || ($partnership->ends_at && $at->gte($partnership->ends_at))) {
            return $this->result('NONE', null, 'outside_partnership');
        }
        $knownAt ??= now();
        $effectiveInstant = $at->format('Y-m-d H:i:s.uP');
        $auditInstant = $knownAt->format('Y-m-d H:i:s.uP');
        $groups = $product?->groupMemberships()->where('joined_at', '<=', $effectiveInstant)
            ->where(fn (Builder $q) => $q->whereNull('left_at')->orWhere('left_at', '>', $effectiveInstant))
            ->pluck('product_group_id') ?? collect();
        $rules = $partnership->commissionDrafts()
            ->where('recorded_at', '<=', $auditInstant)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $effectiveInstant))
            ->whereNotExists(function ($q) use ($effectiveInstant, $auditInstant): void {
                $q->selectRaw('1')->from('shop_commission_drafts as newer')
                    ->whereColumn('newer.configuration_key', 'shop_commission_drafts.configuration_key')
                    ->whereColumn('newer.version', '>', 'shop_commission_drafts.version')
                    ->where('newer.recorded_at', '<=', $auditInstant)
                    ->where(fn ($dates) => $dates->whereNull('newer.effective_from')->orWhere('newer.effective_from', '<=', $effectiveInstant));
            })
            ->whereNotNull('method')->where('currency_id', $currency->id)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveInstant))
            ->where(function (Builder $q) use ($product, $groups): void {
                if ($product) {
                    $q->where('product_id', $product->id);
                }
                $q->orWhereIn('product_group_id', $groups)->orWhere(fn (Builder $scope) => $scope->whereNull('product_id')->whereNull('product_group_id'));
            })->get();
        $candidates = $product ? $rules->where('product_id', $product->id) : collect();
        if ($candidates->isEmpty()) {
            $candidates = $rules->whereNotNull('product_group_id');
            if ($candidates->isNotEmpty()) {
                $candidates = $candidates->where('group_priority', $candidates->max('group_priority'));
            } else {
                $candidates = $rules->whereNull('product_id')->whereNull('product_group_id');
            }
        }
        if ($candidates->count() > 1) {
            return $this->result('CONFLICT', null, 'ambiguous');
        }

        return $candidates->isEmpty() ? $this->result('NONE', null, 'no_match') : $this->result('MATCHED', $candidates->first(), 'preview_only');
    }

    public function assertActivationReady(User $actor, Shop $shop, ShopPartnership $partnership, Product $product, Currency $currency, Carbon $at): void
    {
        app(ShopCommissionDraftService::class)->assertAccess($actor, $shop, $partnership);
        Gate::forUser($actor)->authorize('shop_partnerships.update');
        $result = $this->preview($actor, $shop, $partnership, $product, $currency, $at);
        if ($result['status'] === 'CONFLICT') {
            throw ValidationException::withMessages(['commission' => __('commissions.ambiguous')]);
        }
        if (! Schema::hasTable('shop_commission_activation_events')) {
            throw ValidationException::withMessages(['commission' => __('commissions.activation_required')]);
        }
        if ($result['status'] !== 'MATCHED') {
            throw ValidationException::withMessages(['commission' => __('commissions.no_match')]);
        }
    }

    private function result(string $status, ?ShopCommissionDraft $rule, string $reason): array
    {
        return ['status' => $status, 'rule' => $rule, 'reason' => $reason];
    }
}
