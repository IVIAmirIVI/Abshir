<?php

namespace App\Services;

use App\Models\ShopCommissionDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class CommissionCalculator
{
    /** $netProductValue is the final discounted Invoice line, never delivery or an external charge. */
    public function calculate(?ShopCommissionDraft $rule, string $quantity, string $netProductValue, int $decimalPlaces): string
    {
        $amount = match ($rule?->method) {
            'PERCENTAGE' => BigDecimal::of($netProductValue)->multipliedBy($rule->commission_value)->withPointMovedLeft(2),
            'FIXED' => BigDecimal::of($quantity)->multipliedBy($rule->commission_value),
            default => BigDecimal::zero(),
        };

        return (string) $amount->toScale($decimalPlaces, RoundingMode::HalfUp);
    }
}
