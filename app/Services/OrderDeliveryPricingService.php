<?php

namespace App\Services;

use App\Enums\DeliveryOverrideStatus;
use App\Enums\DeliveryPricingStatus;
use App\Models\Area;
use App\Models\DeliveryFeeOverrideRequest;
use App\Models\DeliveryPricingUserLimit;
use App\Models\Order;
use App\Models\OrderDeliveryPricing;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderDeliveryPricingService
{
    public function __construct(private readonly DeliveryPricingRuleService $rules, private readonly DeliveryPricingSettings $settings, private readonly DeliveryAllocationService $allocations) {}

    public function preview(User $actor, Order $order, Area $origin): array
    {
        Gate::forUser($actor)->authorize('delivery_pricing.view');
        $this->access($actor, $order);
        $currency = $order->branch->defaultCurrency;
        if (! $currency) {
            throw ValidationException::withMessages(['currency' => 'The Order Branch requires a default Currency.']);
        }
        $destination = $order->destinationArea;
        if (! $destination) {
            throw ValidationException::withMessages(['destination_area_id' => 'Confirm the historical destination Area before pricing.']);
        }
        abort_unless(Area::query()->accessibleTo($actor)->whereKey($origin)->exists(), 403);
        if ($origin->branch_id !== $order->branch_id) {
            throw ValidationException::withMessages(['from_area_id' => 'The origin Area must belong to the Order Branch.']);
        }
        $rule = $this->rules->applicable($origin->id, $destination->id, $currency->id, $order->ordered_at);

        return ['rule' => $rule, 'currency' => $currency, 'origin' => $origin, 'destination' => $destination];
    }

    public function confirmHistoricalDestination(User $actor, Order $order, Area $destination): void
    {
        Gate::forUser($actor)->authorize('delivery_pricing.apply');
        $this->access($actor, $order);
        abort_unless(Area::query()->accessibleTo($actor)->whereKey($destination)->exists(), 403);
        if ($destination->branch_id !== $order->branch_id || ! $destination->is_active) {
            throw ValidationException::withMessages(['destination_area_id' => 'Select an active destination Area in the Order Branch.']);
        }

        DB::transaction(function () use ($actor, $order, $destination): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->destination_area_id) {
                throw ValidationException::withMessages(['destination_area_id' => 'The Order already has a confirmed destination Area.']);
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
            $preview = $this->preview($actor, $order, $origin);
            if (! $preview['rule']) {
                throw ValidationException::withMessages(['from_area_id' => 'NO DELIVERY PRICE RULE']);
            }
            $this->validateCurrencyPrecision($appliedFee ?? $preview['rule']->delivery_unit_fee, $preview['currency']->decimal_places, 'applied_unit_fee');
            $this->validateCurrencyPrecision($discount, $preview['currency']->decimal_places, 'discount_amount');
            $share = $this->settings->captainSharePercent();
            if (! $share) {
                throw ValidationException::withMessages(['captain_share' => 'Captain delivery-share percentage must be configured.']);
            }
            $active = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();
            if ($active->isEmpty()) {
                throw ValidationException::withMessages(['captains' => 'Final delivery pricing requires at least one active Captain.']);
            }

            return $this->createVersion($actor, $order, $preview['rule'], $origin, $preview['destination'], $preview['currency'], $active, $appliedFee ?? $preview['rule']->delivery_unit_fee, $share, $discount, $discountReason);
        });
    }

    public function requestOverride(User $actor, Order $order, Area $origin, string $requestedFee, string $reason): OrderDeliveryPricing|DeliveryFeeOverrideRequest
    {
        Gate::forUser($actor)->authorize('delivery_pricing.override');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Override reason is required.']);
        }
        $preview = $this->preview($actor, $order, $origin);
        if (! $preview['rule']) {
            throw ValidationException::withMessages(['from_area_id' => 'NO DELIVERY PRICE RULE']);
        }
        $suggested = BigDecimal::of($preview['rule']->delivery_unit_fee);
        $requested = BigDecimal::of($requestedFee);
        if ($requested->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['requested_unit_fee' => 'Applied fee must be positive.']);
        }
        $this->validateCurrencyPrecision($requestedFee, $preview['currency']->decimal_places, 'requested_unit_fee');
        $difference = $requested->minus($suggested)->abs();
        $percent = $difference->multipliedBy(100)->dividedBy($suggested, 4, RoundingMode::HalfUp);
        if ($actor->isSuperAdmin() || $percent->isLessThanOrEqualTo($this->settings->overrideThresholdPercent())) {
            return DB::transaction(function () use ($actor, $order, $origin, $requestedFee, $reason, $suggested, $requested, $difference, $percent): OrderDeliveryPricing {
                $pricing = $this->priceWithOrderLock($actor, $order, $origin, $requestedFee);
                DeliveryFeeOverrideRequest::query()->create([
                    'order_id' => $order->id,
                    'order_delivery_pricing_id' => $pricing->id,
                    'from_area_id' => $origin->id,
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
            });
        }

        return DeliveryFeeOverrideRequest::query()->create(['order_id' => $order->id, 'order_delivery_pricing_id' => $order->currentDeliveryPricing?->id, 'from_area_id' => $origin->id, 'suggested_unit_fee' => (string) $suggested, 'requested_unit_fee' => $requestedFee, 'difference_amount' => (string) $difference, 'difference_percent' => (string) $percent, 'reason' => $reason, 'status' => DeliveryOverrideStatus::Pending, 'requested_by' => $actor->id, 'requested_at' => now()]);
    }

    public function resolveOverride(User $actor, DeliveryFeeOverrideRequest $request, bool $approve, ?string $resolutionReason): void
    {
        Gate::forUser($actor)->authorize('delivery_pricing_overrides.approve');
        DB::transaction(function () use ($actor, $request, $approve, $resolutionReason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($request->order_id);
            $request = DeliveryFeeOverrideRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== DeliveryOverrideStatus::Pending) {
                throw ValidationException::withMessages(['override' => 'This override request is already resolved.']);
            }
            $this->access($actor, $order);
            if ($approve) {
                $origin = $request->fromArea;
                $preview = $this->preview($actor, $order, $origin);
                if (! $preview['rule'] || ! BigDecimal::of($preview['rule']->delivery_unit_fee)->isEqualTo(BigDecimal::of($request->suggested_unit_fee))) {
                    throw ValidationException::withMessages(['override' => 'The applicable suggested delivery price changed. Submit a new override request.']);
                }
                $pricing = $this->priceWithOrderLock($actor, $order, $origin, $request->requested_unit_fee);
                $request->order_delivery_pricing_id = $pricing->id;
            }
            $request->update(['status' => $approve ? DeliveryOverrideStatus::Approved : DeliveryOverrideStatus::Rejected, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_reason' => $resolutionReason]);
        });
    }

    public function applyDiscount(User $actor, Order $order, string $amount, ?string $reason): OrderDeliveryPricing
    {
        Gate::forUser($actor)->authorize('delivery_discounts.apply');
        if (BigDecimal::of($amount)->isGreaterThan(0) && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for a non-zero discount.']);
        }

        return DB::transaction(function () use ($actor, $order, $amount, $reason): OrderDeliveryPricing {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $current = $order->currentDeliveryPricing()->with(['rule', 'fromArea', 'toArea', 'currency'])->lockForUpdate()->firstOrFail();
            $discount = BigDecimal::of($amount);
            $gross = BigDecimal::of($current->gross_delivery_total);
            $this->validateCurrencyPrecision($amount, $current->currency->decimal_places, 'discount_amount');
            if ($discount->isLessThan(0) || $discount->isGreaterThan($gross)) {
                throw ValidationException::withMessages(['discount_amount' => 'Discount must be between zero and the gross delivery total.']);
            }
            if (! $actor->isSuperAdmin()) {
                $limit = BigDecimal::of(DeliveryPricingUserLimit::query()->where('user_id', $actor->id)->value('max_discount_percent') ?? '0');
                $percent = $gross->isZero() ? BigDecimal::zero() : $discount->multipliedBy(100)->dividedBy($gross, 4, RoundingMode::HalfUp);
                if ($percent->isGreaterThan($limit)) {
                    throw ValidationException::withMessages(['discount_amount' => 'Discount exceeds your configured limit.']);
                }
            }
            $active = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();

            return $this->createVersion($actor, $order, $current->rule, $current->fromArea, $current->toArea, $current->currency, $active, $current->applied_unit_fee, BigDecimal::of($current->captain_share_percent_snapshot), $amount, $reason);
        });
    }

    public function repriceAfterCaptainChangeLocked(User $actor, Order $order): void
    {
        $current = $order->currentDeliveryPricing()->with(['rule', 'fromArea', 'toArea', 'currency'])->lockForUpdate()->first();
        if (! $current) {
            return;
        }
        $active = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();
        if ($active->count() === $current->captain_count_snapshot) {
            return;
        }
        if ($active->isEmpty()) {
            $current->update(['superseded_at' => now()]);

            return;
        }
        $this->createVersion($actor, $order, $current->rule, $current->fromArea, $current->toArea, $current->currency, $active, $current->applied_unit_fee, BigDecimal::of($current->captain_share_percent_snapshot), $current->discount_amount, $current->discount_reason);
    }

    private function createVersion(User $actor, Order $order, mixed $rule, Area $origin, Area $destination, mixed $currency, mixed $active, string $appliedFee, BigDecimal $share, string $discount, ?string $reason): OrderDeliveryPricing
    {
        $gross = BigDecimal::of($appliedFee)->multipliedBy($active->count());
        if (BigDecimal::of($discount)->isGreaterThan($gross)) {
            throw ValidationException::withMessages(['discount_amount' => 'Discount exceeds gross delivery total.']);
        }
        $current = $order->currentDeliveryPricing()->lockForUpdate()->first();
        $version = ((int) $order->deliveryPricings()->max('version')) + 1;
        if ($current) {
            $current->update(['superseded_at' => now()]);
        }
        $pricing = $order->deliveryPricings()->create(['version' => $version, 'delivery_pricing_rule_id' => $rule?->id, 'from_area_id' => $origin->id, 'to_area_id' => $destination->id, 'currency_id' => $currency->id, 'suggested_unit_fee' => $rule?->delivery_unit_fee ?? $appliedFee, 'applied_unit_fee' => $appliedFee, 'captain_count_snapshot' => $active->count(), 'captain_share_percent_snapshot' => (string) $share, 'gross_delivery_total' => (string) $gross, 'discount_amount' => $discount, 'discount_reason' => $reason, 'discount_applied_by' => BigDecimal::of($discount)->isGreaterThan(0) ? $actor->id : null, 'discount_applied_at' => BigDecimal::of($discount)->isGreaterThan(0) ? now() : null, 'customer_delivery_total' => (string) $gross->minus($discount), 'status' => DeliveryPricingStatus::Priced, 'priced_at' => now(), 'created_by' => $actor->id]);
        $current?->update(['superseded_by' => $pricing->id]);
        foreach ($this->allocations->calculate($appliedFee, (string) $share, $discount, $currency, $active) as $row) {
            $pricing->allocations()->create([...$row, 'currency_id' => $currency->id, 'captain_share_percent_snapshot' => (string) $share]);
        }

        return $pricing;
    }

    private function access(User $actor, Order $order): void
    {
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
    }

    private function validateCurrencyPrecision(string $amount, int $decimalPlaces, string $field): void
    {
        if (BigDecimal::of($amount)->strippedOfTrailingZeros()->getScale() > $decimalPlaces) {
            throw ValidationException::withMessages([$field => "The amount exceeds the Currency's minor-unit precision."]);
        }
    }
}
