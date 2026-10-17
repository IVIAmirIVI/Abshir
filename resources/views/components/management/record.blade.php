<article {{ $attributes->class(['min-w-0 h-full']) }}>
    <x-mary-card :id="'management-record-'.md5((string) $slot)" class="h-full min-w-0 border border-stone-200" body-class="space-y-3">
        {{ $slot }}
    </x-mary-card>
</article>
