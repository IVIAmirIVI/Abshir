@props(['value'])
<x-ltr {{ $attributes }}>{{ \App\Support\UiDecimal::format($value) }}</x-ltr>
