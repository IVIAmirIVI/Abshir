<?php

namespace Tests\Unit;

use App\Support\UiDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UiDecimalTest extends TestCase
{
    #[DataProvider('exactDecimals')]
    public function test_only_insignificant_fractional_zeros_are_removed(string|int $input, string $expected): void
    {
        $this->assertSame($expected, UiDecimal::format($input));
    }

    public static function exactDecimals(): array
    {
        return [
            'money integer' => ['50.0000', '50'],
            'quantity integer' => ['1.0000', '1'],
            'quantity fraction' => ['1.2500', '1.25'],
            'all significant digits' => ['1.234567890123456789', '1.234567890123456789'],
            'small fraction' => ['0.0001', '0.0001'],
            'zero' => ['0.0000', '0'],
            'negative zero' => ['-0.0000', '0'],
            'negative amount' => ['-15.2300', '-15.23'],
            'large exact amount' => ['123456789012345678901234567890.1200', '123456789012345678901234567890.12'],
            'integer trailing zeros' => ['1000000', '1000000'],
            'integer input' => [50, '50'],
        ];
    }

    #[DataProvider('invalidDecimals')]
    public function test_inexact_or_invalid_values_are_rejected(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        UiDecimal::format($input);
    }

    public static function invalidDecimals(): array
    {
        return [[''], [null], [true], [1.25], ['1e4'], ['NaN'], ['Infinity'], ['١.٢٥'], ['<script>']];
    }
}
