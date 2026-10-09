<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Presentation only: never changes stored timestamps, input values or business-day queries. */
final class UiDate
{
    public static function datetime(DateTimeInterface|string|null $value, bool $seconds = false): string
    {
        return $value === null ? '—' : CarbonImmutable::parse($value)->setTimezone('Asia/Damascus')->format($seconds ? 'd/m/Y H:i:s' : 'd/m/Y H:i');
    }

    /** Calendar dates are not instants: retain their original year/month/day. */
    public static function date(DateTimeInterface|string|null $value): string
    {
        return $value === null ? '—' : CarbonImmutable::parse($value)->format('d/m/Y');
    }

    public static function datetimeDate(DateTimeInterface|string|null $value): string
    {
        return $value === null ? '—' : CarbonImmutable::parse($value)->setTimezone('Asia/Damascus')->format('d/m/Y');
    }

    public static function longDate(DateTimeInterface|string $value): string
    {
        $date = CarbonImmutable::parse($value);

        return $date->format('j').' '.__('dates.months.'.$date->month).' '.$date->format('Y');
    }

    public static function weekday(DateTimeInterface|string $value): string
    {
        return __('dates.days.'.CarbonImmutable::parse($value)->dayOfWeek);
    }
}
