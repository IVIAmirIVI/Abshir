<?php

namespace App\Services;

use App\Models\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Read-only presentation using the Invoice line rounding convention. */
class OrderLineTotalService
{
    public function calculate(?string $quantity, ?string $unitPrice, ?Currency $currency): ?string
    {
        if (! $currency || ! preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D', $quantity ?? '')
            || ! preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D', $unitPrice ?? '')) {
            return null;
        }
        $quantity = BigDecimal::of($quantity);
        $price = BigDecimal::of($unitPrice);
        if (! $quantity->isGreaterThan(0) || $price->isLessThan(0)) {
            return null;
        }

        return (string) $quantity->multipliedBy($price)->toScale($currency->decimal_places, RoundingMode::HalfUp)->toScale(4);
    }

    public function shop(iterable $items, bool $actual): array
    {
        $totals = [];
        foreach ($items as $item) {
            $currency = $actual ? $item->actualCurrency : $item->estimatedCurrency;
            $total = $this->calculate($item->current_quantity, $actual ? $item->actual_unit_price : $item->estimated_unit_price, $currency);
            if ($total === null) {
                return []; // Never present an incomplete subtotal as the whole Shop total.
            }
            $totals[$currency->code] = (string) BigDecimal::of($totals[$currency->code] ?? '0')->plus($total)->toScale(4);
        }

        return $totals;
    }
}
