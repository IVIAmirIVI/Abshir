@props(['value', 'dateOnly' => false, 'long' => false, 'seconds' => false])
<bdi dir="{{ $long ? \App\Support\UiLabels::direction() : 'ltr' }}" {{ $attributes->class(['ui-technical']) }}>{{ $long && $value !== null ? \App\Support\UiDate::longDate($value) : ($dateOnly ? \App\Support\UiDate::date($value) : \App\Support\UiDate::datetime($value, $seconds)) }}</bdi>
