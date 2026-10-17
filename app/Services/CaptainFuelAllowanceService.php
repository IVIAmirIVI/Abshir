<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaptainFuelAllowanceService
{
    public const PATTERN = '/\A[0-9]{1,3}(?:\.[0-9]{1,4})?\z/';

    public function lockConfiguration(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['captain_delivery_percentage_configuration']);
    }

    public function percentage(mixed $value, ?BigDecimal $base = null): BigDecimal
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match(self::PATTERN, (string) $value)) {
            throw ValidationException::withMessages(['fuel_allowance_percentage' => __('captains.fuel_invalid')]);
        }
        $fuel = BigDecimal::of($value);
        if ($fuel->isGreaterThan(100) || ($base !== null && ($base->isLessThan(0) || $base->plus($fuel)->isGreaterThan(100)))) {
            throw ValidationException::withMessages(['fuel_allowance_percentage' => __('captains.fuel_exceeds_share')]);
        }

        return $fuel->toScale(4);
    }
}
