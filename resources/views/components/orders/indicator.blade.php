@props(['tone' => 'neutral'])
@php($style = match($tone) { 'pending' => 'bg-amber-50 text-amber-900 border-amber-200', 'complete', 'approved' => 'bg-green-50 text-green-900 border-green-200', 'blocked' => 'bg-red-50 text-red-800 border-red-200', default => 'bg-stone-50 text-gray-700 border-stone-200' })
<span {{ $attributes->class(['inline-flex max-w-full items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold leading-5', $style]) }}>{{ $slot }}</span>
