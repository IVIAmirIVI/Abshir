@props(['title', 'current' => false, 'done' => false, 'number' => null])
<span class="flex min-w-0 items-center gap-2">
    @if($number !== null)<bdi dir="ltr" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-current text-xs">{{ $number }}</bdi>@endif
    <span class="min-w-0 break-words">{{ $title }}</span>
    @if($done)<span aria-hidden="true" class="ms-auto">✓</span>@endif
</span>
