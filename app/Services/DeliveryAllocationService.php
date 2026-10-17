<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\OrderCaptain;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class DeliveryAllocationService
{
    public function calculate(string $unitFee, string $sharePercent, string $discount, Currency $currency, iterable $assignments, ?array $fuelPercentages = null, array $specialParts = []): array
    {
        $rows = collect($assignments)->sortBy('id')->values();
        $scale = $currency->decimal_places;
        $factor = 10 ** $scale;
        $discountMinor = BigDecimal::of($discount)->multipliedBy($factor)->toScale(0, RoundingMode::HalfUp)->toInt();
        $baseMinor = intdiv($discountMinor, max(1, $rows->count()));
        $remainder = $discountMinor % max(1, $rows->count());
        $fee = BigDecimal::of($unitFee)->toScale($scale, RoundingMode::HalfUp);
        $base = BigDecimal::of($sharePercent);
        $baseEntitlement = $fee->multipliedBy($base)->dividedBy(100, $scale, RoundingMode::HalfUp);

        return $rows->map(function (OrderCaptain $assignment, int $index) use ($baseMinor, $remainder, $factor, $scale, $fee, $base, $baseEntitlement, $fuelPercentages, $specialParts): array {
            $fuel = app(CaptainFuelAllowanceService::class)->percentage($fuelPercentages === null ? '0' : ($fuelPercentages[$assignment->captain_id] ?? null), $base);
            $effective = $base->plus($fuel);
            // Round the final entitlement once, as before; attribute the exact rounded difference to fuel.
            $entitlement = $fee->multipliedBy($effective)->dividedBy(100, $scale, RoundingMode::HalfUp);
            $baseAmount = $baseEntitlement;
            $components = null;
            if ($specialParts !== []) {
                $parts = $specialParts[$assignment->captain_id] ?? [];
                $specialFee = BigDecimal::zero();
                $standardFee = BigDecimal::zero();
                $customCaptain = BigDecimal::zero();
                foreach ($parts as $part) {
                    $specialFee = $specialFee->plus($part['base_fee']);
                    if ($part['mode'] === 'DEFAULT') {
                        $standardFee = $standardFee->plus($part['base_fee']);
                    } else {
                        $customCaptain = $customCaptain->plus($part['custom_captain']);
                    }
                }
                if ($parts === [] || $fee->isLessThan($specialFee)) {
                    throw ValidationException::withMessages(['delivery' => __('special.decrease_blocked')]);
                }
                $extra = $fee->minus($specialFee);
                $standardFee = $standardFee->plus($extra);
                $entitlement = $customCaptain->plus($standardFee->multipliedBy($effective)->dividedBy(100, $scale, RoundingMode::HalfUp));
                $baseAmount = $customCaptain->plus($standardFee->multipliedBy($base)->dividedBy(100, $scale, RoundingMode::HalfUp));
                $components = ['parts' => $parts, 'special_base_fee' => (string) $specialFee->toScale(4),
                    'custom_captain' => (string) $customCaptain->toScale(4), 'extra_fee' => (string) $extra->toScale(4),
                    'standard_fee' => (string) $standardFee->toScale(4)];
            }
            $allocated = BigDecimal::of($baseMinor + ($index < $remainder ? 1 : 0))->dividedBy($factor, $scale, RoundingMode::Unnecessary);
            $customer = $fee->minus($allocated);
            if ($components !== null && $customer->isLessThan($components['special_base_fee'])) {
                throw ValidationException::withMessages(['discount_amount' => __('special.decrease_blocked')]);
            }

            $row = [
                'order_captain_id' => $assignment->id,
                'captain_id' => $assignment->captain_id,
                'applied_unit_fee' => (string) $fee->toScale(4),
                'captain_entitlement' => (string) $entitlement->toScale(4),
                'discount_allocated' => (string) $allocated->toScale(4),
                'customer_delivery_amount_allocated' => (string) $customer->toScale(4),
                'company_collectible' => (string) ($customer->isGreaterThan($entitlement) ? $customer->minus($entitlement) : BigDecimal::zero())->toScale(4),
                'captain_shortfall' => (string) ($entitlement->isGreaterThan($customer) ? $entitlement->minus($customer) : BigDecimal::zero())->toScale(4),
            ];

            // Legacy historical-schema fixtures retain the original return shape when no configuration is supplied.
            return $fuelPercentages === null ? $row : $row + [
                'base_captain_share_percent_snapshot' => (string) $base->toScale(4),
                'fuel_allowance_percent_snapshot' => (string) $fuel,
                'captain_share_percent_snapshot' => (string) $effective->toScale(4),
                'base_captain_entitlement' => (string) $baseAmount->toScale(4),
                'fuel_allowance_amount' => (string) $entitlement->minus($baseAmount)->toScale(4),
            ] + ($components === null ? [] : ['special_delivery_components' => $components]);
        })->all();
    }
}
