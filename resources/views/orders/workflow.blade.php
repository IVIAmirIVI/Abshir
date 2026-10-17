<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('orders.workflow.title') }} · <x-ltr>{{ $order->order_number }}</x-ltr></h2></x-slot>
    <livewire:orders.workflow :order="$order" />
</x-app-layout>
