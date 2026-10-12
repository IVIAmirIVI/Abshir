<?php

namespace App\Support;

use InvalidArgumentException;

/** Display only: remove insignificant fractional zeros without arithmetic or rounding. */
final class UiDecimal
{
    public static function format(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value)) {
            throw new InvalidArgumentException('Decimal display requires an exact decimal string or integer.');
        }

        $display = (string) $value;
        if (str_contains($display, '.')) {
            $display = rtrim(rtrim($display, '0'), '.');
        }

        return $display === '-0' ? '0' : $display;
    }
}
