@props(['type' => 'submit', 'variant' => 'primary'])
@php($style = match($variant) { 'secondary' => 'btn-outline', 'danger' => 'btn-error btn-outline', 'quiet' => 'btn-ghost', default => 'btn-primary' })
<x-mary-button :id="'order-action-'.md5(json_encode($attributes->getAttributes()).(string)$slot)" :type="$type" {{ $attributes->class([$style, 'min-h-11 max-w-full whitespace-normal text-sm']) }}>{{ $slot }}</x-mary-button>
