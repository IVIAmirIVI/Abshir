@props(['amount', 'currency' => null])
@php
    // Presentation only: preserve every significant decimal digit, without arithmetic.
    if (!is_string($amount) || !preg_match('/^-?\d+(?:\.\d+)?$/D', $amount)) {
        throw new InvalidArgumentException('Money display requires an exact decimal string.');
    }
@endphp
<x-ltr {{ $attributes }}>{{ \App\Support\UiDecimal::format($amount) }}@if($currency !== null) {{ $currency }}@endif</x-ltr>
