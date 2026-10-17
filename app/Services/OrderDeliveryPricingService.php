<?php

namespace App\Services;

use App\Enums\DeliveryOverrideStatus;
use App\Enums\DeliveryPricingStatus;
use App\Enums\OrderPricingClass;
use App\Models\Area;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\DeliveryFeeOverrideRequest;
use App\Models\DeliveryPricingRule;
use App\Models\DeliveryPricingUserLimit;
use App\Models\Order;
use App\Models\OrderDeliveryPricing;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderDeliveryPricingService
{
    public function __construct(private readonly DeliveryPricingRuleService $rules, private readonly DeliveryPricingSettings $settings, private readonly DeliveryAllocationService $allocations, private readonly OrderMutationGuard $mutationGuard, private readonly OrderDeliveryOriginService $origins) {}

    public function preview(User $actor, Order $order, ?Area $origin = null): array
    {
        // A caller may retain its Order across Shop mutations. Do not combine
        // stale classification/dimensions with freshly queried Shop routes.
        $order = Order::query()->findOrFail($order->id);

        return $this->proposal($actor, $order, $origin, false);
    }

    private function proposal(User $actor, Order $order, ?Area $requestedOrigin, bool $lockShops): array
    {
        Gate::forUser($actor)->authorize('delivery_pricing.view');
        $this->access($actor, $order);
        if (! $lockShops && $this->mutationGuard->isPricingFrozen($order)) {
            $pricing = $order->currentDeliveryPricing()->with(['rule', 'fromArea', 'toArea', 'currency', 'routes.rule', 'routes.fromArea', 'routes.toArea'])->first();
            $routes = $pricing?->routes->map(fn ($route): array => [
                'shop' => (object) ['id' => $route->order_shop_id, 'shop_name_snapshot' => $route->shop_name_snapshot],
                'origin' => $route->fromArea, 'destination' => $route->toArea,
                'rule' => $route->rule, 'proposed_unit_fee' => $route->proposed_unit_fee,
                'is_selected_max' => $route->is_selected_max,
            ]) ?? collect();

            return [
                'rule' => $pricing?->rule, 'currency' => $pricing?->currency,
                'origin' => $pricing?->fromArea, 'destination' => $pricing?->toArea,
                'routes' => $routes, 'complete' => $pricing !== null && $pricing->rule !== null,
                'is_multi_shop' => $pricing?->order_pricing_class_snapshot === OrderPricingClass::MultiShop,
                'is_frozen' => true, 'suggested_unit_fee' => $pricing?->suggested_unit_fee,
            ];
        }
        $currency = $order->branch->defaultCurrency;
        if (! $currency) {
            throw ValidationException::withMessages(['currency' => __('domain.the_order_branch_requires_a_default_currency')]);
        }
        $destination = $order->destinationArea;
        if (! $destination) {
            throw ValidationException::withMessages(['destination_area_id' => __('domain.confirm_the_historical_destination_area_before_pricing')]);
        }
        if (! $order->pricing_class) {
            throw ValidationException::withMessages(['pricing_class' => __('domain.add_and_classify_at_least_one_order_shop_before_delivery_pricing')]);
        }

        $routes = $this->origins->routes($order, $lockShops);
        if ($routes->isEmpty()) {
            throw ValidationException::withMessages(['from_area_id' => __('domain.add_and_classify_at_least_one_order_shop_before_delivery_pricing')]);
        }
        if ($requestedOrigin) {
            abort_unless(Area::query()->accessibleTo($actor)->whereKey($requestedOrigin)->exists(), 403);
            if ($requestedOrigin->branch_id !== $order->branch_id) {
                throw ValidationException::withMessages(['from_area_id' => __('domain.the_origin_area_must_belong_to_the_order_branch')]);
            }
        }
        if ($routes->count() === 1 && $requestedOrigin && $routes->first()['origin']?->id !== $requestedOrigin->id) {
            throw ValidationException::withMessages(['from_area_id' => __('domain.the_delivery_origin_must_match_the_order_shop_snapshot')]);
        }

        $preview = $this->previewRoutes($actor, $routes, $destination, $currency, $order->pricing_class);
        $special = app(SpecialProductDeliveryService::class)->quote(app(SpecialProductDeliveryService::class)->orderSources($order),
            $currency, $order->captainAssignments()->whereNull('removed_at')->pluck('captain_id')->all(), $lockShops, $routes->count());

        return array_replace($preview, $special);
    }

    /** Shared read-only evaluator for pre-creation drafts and mutable Order proposals. */
    public function previewRoutes(User $actor, Collection $routes, Area $destination, Currency $currency, OrderPricingClass $pricingClass): array
    {
        Gate::forUser($actor)->authorize('delivery_pricing.view');
        abort_unless($actor->canAccessArea($destination), 403);
        $pricingAt = now();
        $evaluated = $routes->map(function (array $route) use ($actor, $destination, $currency, $pricingClass, $pricingAt): array {
            $origin = $route['origin'];
            if ($origin) {
                abort_unless(Area::query()->accessibleTo($actor)->whereKey($origin)->exists(), 403);
                if ($origin->branch_id !== $destination->branch_id) {
                    throw ValidationException::withMessages(['from_area_id' => __('domain.the_origin_area_must_belong_to_the_order_branch')]);
                }
            }
            $rule = $origin ? $this->rules->applicable($origin->id, $destination->id, $currency->id, $pricingClass, $pricingAt) : null;

            return [
                'shop' => $route['shop'],
                'origin' => $origin,
                'destination' => $destination,
                'rule' => $rule,
                'proposed_unit_fee' => $rule?->delivery_unit_fee,
                'is_selected_max' => false,
            ];
        });

        $complete = $evaluated->every(fn (array $route): bool => $route['rule'] !== null);
        $selectedShopId = null;
        if ($complete) {
            $selected = $evaluated->reduce(function (?array $highest, array $route): array {
                if (! $highest || BigDecimal::of($route['proposed_unit_fee'])->isGreaterThan(BigDecimal::of($highest['proposed_unit_fee']))) {
                    return $route;
                }

                return $highest;
            });
            $selectedShopId = $selected['shop']->id;
            $evaluated = $evaluated->map(function (array $route) use ($selectedShopId): array {
                $route['is_selected_max'] = $route['shop']->id === $selectedShopId;

                return $route;
            });
        }

        $selected = $complete ? $evaluated->firstWhere('is_selected_max', true) : null;

        return [
            'rule' => $selected['rule'] ?? null,
            'currency' => $currency,
            'origin' => $selected['origin'] ?? null,
            'destination' => $destination,
            'routes' => $evaluated,
            'complete' => $complete,
            'is_multi_shop' => $pricingClass === OrderPricingClass::MultiShop,
            'is_frozen' => false,
            'suggested_unit_fee' => $selected['proposed_unit_fee'] ?? null,
        ];
    }

    public function confirmHistoricalDestination(User $actor, Order $order, Area $destination): void
    {
        Gate::forUser($actor)->authorize('delivery_pricing.apply');
        $this->access($actor, $order);
        abort_unless(Area::query()->accessibleTo($actor)->whereKey($destination)->exists(), 403);
        if ($destination->branch_id !== $order->branch_id || ! $destination->is_active) {
            throw ValidationException::withMessages(['destination_area_id' => __('domain.select_an_active_destination_area_in_the_order_branch')]);
        }

        DB::transaction(function () use ($actor, $order, $destination): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);
            if ($order->destination_area_id) {
                throw ValidationException::withMessages(['destination_area_id' => __('domain.the_order_already_has_a_confirmed_destination_area')]);
            }
            $order->update(['destination_area_id' => $destination->id, 'destination_area_confirmed_by' => $actor->id, 'destination_area_confirmed_at' => now()]);
        });
    }

    public function price(User $actor, Order $order, Area $origin, ?string $appliedFee = null, string $discount = '0', ?string $discountReason = null): OrderDeliveryPricing
    {
        Gate::forUser($actor)->authorize('delivery_pricing.apply');

        return $this->priceWithOrderLock($actor, $order, $origin, $appliedFee, $discount, $discountReason);
    }

    private function priceWithOrderLock(User $actor, Order $order, Area $origin, ?string $appliedFee = null, string $discount = '0', ?string $discountReason = null): OrderDeliveryPricing
    {
        return DB::transaction(function () use ($actor, $order, $origin, $appliedFee, $discount, $discountReason): OrderDeliveryPricing {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            return $this->priceLocked($actor, $order, $origin, $appliedFee, $discount, $discountReason);
        });
    }

    public function requestOverride(User $actor, Order $order, Area $origin, string $requestedFee, string $reason): OrderDeliveryPricing|DeliveryFeeOverrideRequest
    {
        Gate::forUser($actor)->authorize('delivery_pricing.override');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('domain.override_reason_is_required')]);
        }

        return DB::transaction(function () use ($actor, $order, $origin, $requestedFee, $reason): OrderDeliveryPricing|DeliveryFeeOverrideRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->mutationGuard->assertMutableLocked($order);
            $preview = $this->proposal($actor, $order, $origin, true);
            if (! $preview['rule']) {
                throw ValidationException::withMessages(['from_area_id' => __('domain.no_delivery_price_rule')]);
            }
            $origin = $preview['origin'];
            if (isset($preview['special_parts']) && BigDecimal::of($requestedFee)->isLessThan($preview['suggested_unit_fee'])) {
                throw ValidationException::withMessages(['requested_unit_fee' => __('special.decrease_blocked')]);
            }
            $decision = $this->overrideDecision($actor, $preview['currency'], $preview['suggested_unit_fee'], $requestedFee);
            $suggested = $decision['suggested'];
            $requested = $decision['requested'];
            $difference = $decision['difference'];
            $percent = $decision['percent'];
            if ($decision['immediate']) {
                $pricing = $this->priceFromPreviewLocked($actor, $order, $preview, $requestedFee);
                DeliveryFeeOverrideRequest::query()->create([
                    'order_id' => $order->id,
                    'order_delivery_pricing_id' => $pricing->id,
                    'from_area_id' => $origin->id,
                    'order_pricing_class_snapshot' => $pricing->order_pricing_class_snapshot,
                    'suggested_unit_fee' => (string) $suggested,
                    'requested_unit_fee' => (string) $requested,
                    'difference_amount' => (string) $difference,
                    'difference_percent' => (string) $percent,
                    'reason' => $reason,
                    'status' => DeliveryOverrideStatus::Approved,
                    'requested_by' => $actor->id,
                    'requested_at' => now(),
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                    'resolution_reason' => $actor->isSuperAdmin() ? 'Super Admin direct override.' : 'Within configured override threshold.',
                ]);

                return $pricing;
            }

            $currentPricingId = $order->currentDeliveryPricing()->lockForUpdate()->value('id');

            return DeliveryFeeOverrideRequest::query()->create(['order_id' => $order->id, 'order_delivery_pricing_id' => $currentPricingId, 'from_area_id' => $origin->id, 'order_pricing_class_snapshot' => $order->pricing_class, 'suggested_unit_fee' => (string) $suggested, 'requested_unit_fee' => $requestedFee, 'difference_amount' => (string) $difference, 'difference_percent' => (string) $percent, 'reason' => $reason, 'status' => DeliveryOverrideStatus::Pending, 'requested_by' => $actor->id, 'requested_at' => now()]);
        });
    }

    /** Preview and persistence share the existing threshold and exact decimal policy. */
    public function overrideDecision(User $actor, Currency $currency, string $suggestedFee, string $requestedFee): array
    {
        Gate::forUser($actor)->authorize('delivery_pricing.override');
        try {
            $requested = BigDecimal::of($requestedFee);
        } catch (MathException) {
            throw ValidationException::withMessages(['requested_unit_fee' => __('domain.applied_fee_must_be_positive')]);
        }
        if ($requested->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['requested_unit_fee' => __('domain.applied_fee_must_be_positive')]);
        }
        $this->validateCurrencyPrecision($requestedFee, $currency->decimal_places, 'requested_unit_fee');
        $suggested = BigDecimal::of($suggestedFee);
        $difference = $requested->minus($suggested)->abs();
        $percent = $difference->multipliedBy(100)->dividedBy($suggested, 4, RoundingMode::HalfUp);

        return compact('suggested', 'requested', 'difference', 'percent') + [
            'immediate' => $actor->isSuperAdmin() || $percent->isLessThanOrEqualTo($this->settings->overrideThresholdPercent()),
        ];
    }

    public function resolveOverride(User $actor, DeliveryFeeOverrideRequest $request, bool $approve, ?string $resolutionReason): void
    {
        Gate::forUser($actor)->authorize('delivery_pricing_overrides.approve');
        DB::transaction(function () use ($actor, $request, $approve, $resolutionReason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($request->order_id);
            $request = DeliveryFeeOverrideRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->access($actor, $order);
            if ($request->status !== DeliveryOverrideStatus::Pending) {
                throw ValidationException::withMessages(['override' => __('domain.this_override_request_is_already_resolved')]);
            }
            $this->mutationGuard->assertMutableLocked($order);
            if ($approve) {
                if ($request->order_pricing_class_snapshot !== $order->pricing_class) {
                    throw ValidationException::withMessages(['override' => __('domain.the_order_pricing_class_changed_submit_a_new_override_request')]);
                }
                $origin = $request->fromArea;
                $preview = $this->proposal($actor, $order, $origin, true);
                if (! $preview['rule'] || ! BigDecimal::of($preview['suggested_unit_fee'])->isEqualTo(BigDecimal::of($request->suggested_unit_fee))) {
                    throw ValidationException::withMessages(['override' => __('domain.the_applicable_suggested_delivery_price_changed_submit_a_new_override_request')]);
                }
                $pricing = $this->priceFromPreviewLocked($actor, $order, $preview, $request->requested_unit_fee);
                $request->order_delivery_pricing_id = $pricing->id;
            }
            $request->update(['status' => $approve ? DeliveryOverrideStatus::Approved : DeliveryOverrideStatus::Rejected, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_reason' => $resolutionReason]);
        });
    }

    public function applyDiscount(User $actor, Order $order, string $amount, ?string $reason): OrderDeliveryPricing
    {
        Gate::forUser($actor)->authorize('delivery_discounts.apply');
        $this->access($actor, $order);
        if (BigDecimal::of($amount)->isGreaterThan(0) && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => __('domain.a_reason_is_required_for_a_non_zero_discount')]);
        }

        return DB::transaction(function () use ($actor, $order, $amount, $reason): OrderDeliveryPricing {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->access($actor, $order);
            $this->mutationGuard->assertMutableLocked($order);
            $current = $order->currentDeliveryPricing()->with(['rule', 'fromArea', 'toArea', 'currency'])->lockForUpdate()->firstOrFail();
            $this->assertReusablePricingSource($order, $current);
            $discount = BigDecimal::of($amount);
            $gross = BigDecimal::of($current->gross_delivery_total);
            $this->validateCurrencyPrecision($amount, $current->currency->decimal_places, 'discount_amount');
            if ($discount->isLessThan(0) || $discount->isGreaterThan($gross)) {
                throw ValidationException::withMessages(['discount_amount' => __('domain.discount_must_be_between_zero_and_the_gross_delivery_total')]);
            }
            if (! $actor->isSuperAdmin()) {
                $limit = BigDecimal::of(DeliveryPricingUserLimit::query()->where('user_id', $actor->id)->value('max_discount_percent') ?? '0');
                $percent = $gross->isZero() ? BigDecimal::zero() : $discount->multipliedBy(100)->dividedBy($gross, 4, RoundingMode::HalfUp);
                if ($percent->isGreaterThan($limit)) {
                    throw ValidationException::withMessages(['discount_amount' => __('domain.discount_exceeds_your_configured_limit')]);
                }
            }
            $active = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();

            return $this->createVersion($actor, $order, $current->rule, $current->fromArea, $current->toArea, $current->currency, $active, $current->applied_unit_fee, BigDecimal::of($current->captain_share_percent_snapshot), $amount, $reason, $this->routeSnapshots($order, $current));
        });
    }

    public function repriceAfterCaptainChangeLocked(User $actor, Order $order): void
    {
        $this->mutationGuard->assertMutableLocked($order);
        $current = $order->currentDeliveryPricing()->with(['rule', 'fromArea', 'toArea', 'currency'])->lockForUpdate()->first();
        if (! $current) {
            return;
        }
        $active = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();
        $previousCaptainIds = $current->allocations()->orderBy('captain_id')->pluck('captain_id')->all();
        $currentCaptainIds = $active->pluck('captain_id')->sort()->values()->all();
        $previousAssignmentIds = $current->allocations()->orderBy('order_captain_id')->pluck('order_captain_id')->all();
        if ($active->count() === $current->captain_count_snapshot && $previousCaptainIds === $currentCaptainIds
            && $previousAssignmentIds === $active->pluck('id')->all()) {
            return;
        }
        if ($active->isEmpty()) {
            $current->update(['superseded_at' => now()]);

            return;
        }
        $this->assertReusablePricingSource($order, $current);
        $this->createVersion($actor, $order, $current->rule, $current->fromArea, $current->toArea, $current->currency, $active, $current->applied_unit_fee, BigDecimal::of($current->captain_share_percent_snapshot), $current->discount_amount, $current->discount_reason, $this->routeSnapshots($order, $current));
    }

    private function createVersion(User $actor, Order $order, mixed $rule, Area $origin, Area $destination, mixed $currency, mixed $active, string $appliedFee, BigDecimal $share, string $discount, ?string $reason, mixed $routeSnapshots): OrderDeliveryPricing
    {
        $this->assertClassifiedRuleForOrder($order, $rule);
        $special = app(SpecialProductDeliveryService::class)->quote(app(SpecialProductDeliveryService::class)->orderSources($order), $currency, $active->pluck('captain_id')->all(), shopCount: $order->shops()->count());
        $gross = BigDecimal::of($appliedFee)->multipliedBy($active->count());
        if (BigDecimal::of($discount)->isGreaterThan($gross)) {
            throw ValidationException::withMessages(['discount_amount' => __('domain.discount_exceeds_gross_delivery_total')]);
        }
        $current = $order->currentDeliveryPricing()->lockForUpdate()->first();
        $version = ((int) $order->deliveryPricings()->max('version')) + 1;
        if ($current) {
            $current->update(['superseded_at' => now()]);
        }
        $pricing = $order->deliveryPricings()->create(['version' => $version, 'delivery_pricing_rule_id' => $rule?->id, 'from_area_id' => $origin->id, 'to_area_id' => $destination->id, 'currency_id' => $currency->id, 'order_pricing_class_snapshot' => $order->pricing_class, 'route_count_snapshot' => $routeSnapshots->count(), 'suggested_unit_fee' => $rule?->delivery_unit_fee ?? $appliedFee, 'applied_unit_fee' => $appliedFee, 'captain_count_snapshot' => $active->count(), 'captain_share_percent_snapshot' => (string) $share, 'gross_delivery_total' => (string) $gross, 'discount_amount' => $discount, 'discount_reason' => $reason, 'discount_applied_by' => BigDecimal::of($discount)->isGreaterThan(0) ? $actor->id : null, 'discount_applied_at' => BigDecimal::of($discount)->isGreaterThan(0) ? now() : null, 'customer_delivery_total' => (string) $gross->minus($discount), 'status' => DeliveryPricingStatus::Priced, 'priced_at' => now(), 'created_by' => $actor->id]);
        $current?->update(['superseded_by' => $pricing->id]);
        if ($special !== []) {
            $pricing->update(['suggested_unit_fee' => $special['suggested_unit_fee']]);
        }
        // Historical V1 migration-state fixtures predate Captain allowance configuration.
        $fuelPercentages = Schema::hasColumn('captains', 'fuel_allowance_percentage')
            ? Captain::query()->whereIn('id', $active->pluck('captain_id'))->orderBy('id')->sharedLock()->pluck('fuel_allowance_percentage', 'id')->all() : null;
        foreach ($this->allocations->calculate($appliedFee, (string) $share, $discount, $currency, $active, $fuelPercentages, $special['special_parts'] ?? []) as $row) {
            $pricing->allocations()->create([...$row, 'currency_id' => $currency->id, 'captain_share_percent_snapshot' => $row['captain_share_percent_snapshot'] ?? (string) $share]);
        }
        foreach ($routeSnapshots as $route) {
            $pricing->routes()->create([
                'order_shop_id' => $route['order_shop_id'],
                'shop_name_snapshot' => $route['shop_name_snapshot'],
                'from_area_id' => $route['from_area_id'],
                'to_area_id' => $route['to_area_id'],
                'delivery_pricing_rule_id' => $route['delivery_pricing_rule_id'],
                'proposed_unit_fee' => $route['proposed_unit_fee'],
                'is_selected_max' => $route['is_selected_max'],
            ]);
        }

        return $pricing;
    }

    private function priceLocked(User $actor, Order $order, Area $origin, ?string $appliedFee = null, string $discount = '0', ?string $discountReason = null): OrderDeliveryPricing
    {
        $this->mutationGuard->assertMutableLocked($order);
        $preview = $this->proposal($actor, $order, $origin, true);

        return $this->priceFromPreviewLocked($actor, $order, $preview, $appliedFee, $discount, $discountReason);
    }

    private function priceFromPreviewLocked(User $actor, Order $order, array $preview, ?string $appliedFee = null, string $discount = '0', ?string $discountReason = null): OrderDeliveryPricing
    {
        if (! $preview['rule']) {
            throw ValidationException::withMessages(['from_area_id' => __('domain.no_delivery_price_rule')]);
        }
        $this->validateCurrencyPrecision($appliedFee ?? $preview['suggested_unit_fee'], $preview['currency']->decimal_places, 'applied_unit_fee');
        $this->validateCurrencyPrecision($discount, $preview['currency']->decimal_places, 'discount_amount');
        $share = $this->settings->captainSharePercent();
        if (! $share) {
            throw ValidationException::withMessages(['captain_share' => __('domain.captain_delivery_share_percentage_must_be_configured')]);
        }
        $active = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();
        if ($active->isEmpty()) {
            throw ValidationException::withMessages(['captains' => __('domain.final_delivery_pricing_requires_at_least_one_active_captain')]);
        }

        return $this->createVersion($actor, $order, $preview['rule'], $preview['origin'], $preview['destination'], $preview['currency'], $active, $appliedFee ?? $preview['suggested_unit_fee'], $share, $discount, $discountReason, $this->routeSnapshotsFromProposal($preview));
    }

    private function assertReusablePricingSource(Order $order, OrderDeliveryPricing $pricing): void
    {
        $hasSpecial = Schema::hasColumn('order_delivery_allocations', 'special_delivery_components')
            && $pricing->allocations()->whereNotNull('special_delivery_components')->exists();
        if ($hasSpecial) {
            app(SpecialProductDeliveryService::class)->assertCurrentPricing($order, $pricing);
        }
        if (! $order->pricing_class || $pricing->order_pricing_class_snapshot !== $order->pricing_class) {
            throw ValidationException::withMessages(['pricing_class' => __('domain.classify_and_reprice_this_legacy_order_before_changing_its_delivery_pricing')]);
        }
        $this->assertClassifiedRuleForOrder($order, $pricing->rule);
        if ($pricing->from_area_id !== $pricing->rule->from_area_id || $pricing->to_area_id !== $pricing->rule->to_area_id || $pricing->currency_id !== $pricing->rule->currency_id) {
            throw ValidationException::withMessages(['delivery_pricing' => __('domain.reprice_the_order_with_an_exact_class_specific_delivery_rule_before_continuing')]);
        }

        $routes = $pricing->routes()->with('rule')->orderBy('order_shop_id')->get();
        if ($routes->isEmpty()) {
            if ($order->pricing_class === OrderPricingClass::MultiShop) {
                throw ValidationException::withMessages(['delivery_pricing' => __('domain.reprice_the_multi_shop_order_to_capture_each_shop_route')]);
            }

            return;
        }
        $currentShops = $order->shops()->orderBy('id')->lockForUpdate()->get(['id', 'origin_area_id']);
        if ($routes->pluck('order_shop_id')->all() !== $currentShops->pluck('id')->all()
            || $routes->pluck('from_area_id')->all() !== $currentShops->pluck('origin_area_id')->all()
            || $routes->where('is_selected_max', true)->count() !== 1) {
            throw ValidationException::withMessages(['delivery_pricing' => __('domain.the_order_shop_routes_changed_reprice_before_continuing')]);
        }
        foreach ($routes as $route) {
            if (! $route->rule || $route->rule->order_pricing_class !== $order->pricing_class
                || $route->rule->from_area_id !== $route->from_area_id
                || $route->rule->to_area_id !== $route->to_area_id
                || $route->rule->currency_id !== $pricing->currency_id
                || $route->rule->delivery_unit_fee !== $route->proposed_unit_fee) {
                throw ValidationException::withMessages(['delivery_pricing' => __('domain.reprice_the_order_with_an_exact_class_specific_delivery_rule_before_continuing')]);
            }
        }
        $selected = $routes->firstWhere('is_selected_max', true);
        if ($selected->delivery_pricing_rule_id !== $pricing->delivery_pricing_rule_id
            || $selected->from_area_id !== $pricing->from_area_id
            || (! $hasSpecial && $selected->proposed_unit_fee !== $pricing->suggested_unit_fee)) {
            throw ValidationException::withMessages(['delivery_pricing' => __('domain.reprice_the_order_with_an_exact_class_specific_delivery_rule_before_continuing')]);
        }
    }

    private function routeSnapshotsFromProposal(array $preview): mixed
    {
        return $preview['routes']->map(fn (array $route): array => [
            'order_shop_id' => $route['shop']->id,
            'shop_name_snapshot' => $route['shop']->shop_name_snapshot,
            'from_area_id' => $route['origin']->id,
            'to_area_id' => $route['destination']->id,
            'delivery_pricing_rule_id' => $route['rule']->id,
            'proposed_unit_fee' => $route['proposed_unit_fee'],
            'is_selected_max' => $route['is_selected_max'],
        ]);
    }

    private function routeSnapshots(Order $order, OrderDeliveryPricing $pricing): mixed
    {
        $routes = $pricing->routes()->orderBy('order_shop_id')->get();
        if ($routes->isNotEmpty()) {
            return $routes->map(fn ($route): array => $route->only([
                'order_shop_id', 'shop_name_snapshot', 'from_area_id', 'to_area_id',
                'delivery_pricing_rule_id', 'proposed_unit_fee', 'is_selected_max',
            ]));
        }
        if ($order->pricing_class === OrderPricingClass::MultiShop) {
            throw ValidationException::withMessages(['delivery_pricing' => __('domain.reprice_the_multi_shop_order_to_capture_each_shop_route')]);
        }
        $shop = $order->shops()->where('origin_area_id', $pricing->from_area_id)->lockForUpdate()->first();
        if (! $shop) {
            throw ValidationException::withMessages(['delivery_pricing' => __('domain.the_order_shop_routes_changed_reprice_before_continuing')]);
        }

        return collect([[
            'order_shop_id' => $shop->id,
            'shop_name_snapshot' => $shop->shop_name_snapshot,
            'from_area_id' => $pricing->from_area_id,
            'to_area_id' => $pricing->to_area_id,
            'delivery_pricing_rule_id' => $pricing->delivery_pricing_rule_id,
            'proposed_unit_fee' => $pricing->suggested_unit_fee,
            'is_selected_max' => true,
        ]]);
    }

    private function assertClassifiedRuleForOrder(Order $order, ?DeliveryPricingRule $rule): void
    {
        if (! $order->pricing_class) {
            throw ValidationException::withMessages(['pricing_class' => __('domain.classify_the_order_shops_before_creating_a_new_delivery_pricing_version')]);
        }
        if (! $rule || $rule->order_pricing_class !== $order->pricing_class) {
            throw ValidationException::withMessages(['delivery_pricing' => __('domain.a_new_pricing_version_requires_an_exact_class_specific_delivery_rule_reprice_the_order_first')]);
        }
    }

    private function access(User $actor, Order $order): void
    {
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
    }

    private function validateCurrencyPrecision(string $amount, int $decimalPlaces, string $field): void
    {
        if (BigDecimal::of($amount)->strippedOfTrailingZeros()->getScale() > $decimalPlaces) {
            throw ValidationException::withMessages([$field => __('domain.the_amount_exceeds_the_currency_s_minor_unit_precision')]);
        }
    }
}
