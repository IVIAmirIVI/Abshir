@props(['title' => null, 'subtitle' => null, 'id'])
<section {{ $attributes->class(['order-section min-w-0']) }}>
    <x-mary-card :id="$id" class="min-w-0 border border-stone-200" body-class="space-y-4">
        @if($title)<header><h3 class="text-lg font-bold text-brand-primary">{{ $title }}</h3>@if($subtitle)<p class="mt-1 text-sm leading-6 text-gray-600">{{ $subtitle }}</p>@endif</header>@endif
        {{ $slot }}
    </x-mary-card>
</section>
