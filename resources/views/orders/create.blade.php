<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">{{ __('orders.ui_create') }}</h2></x-slot>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
        <livewire:orders.create-order />
    </div>
</x-app-layout>
