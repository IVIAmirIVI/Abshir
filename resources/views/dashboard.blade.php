<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-brand-primary">{{ __('messages.dashboard_title') }}</h2></x-slot>
    @if($showCaptainPortal)<div class="px-4 pt-4 sm:px-6 lg:px-8"><a class="btn btn-primary" href="{{ route('captain.portal') }}">{{ __('captain_portal.title') }}</a></div>@endif
    @if($showOperationalDashboard)
        <div class="px-4 pt-4 sm:px-6 lg:px-8"><a class="btn btn-outline" href="{{ route('reports.index') }}">{{ __('reports.title') }}</a></div>
        <livewire:dashboard.operational-dashboard />
    @else
    <div class="py-8 sm:py-12"><div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-gray-200 bg-white p-6 sm:p-8 shadow-sm">
            <p class="text-sm font-semibold text-brand-primary">{{ __('common.brand.name') }} · {{ __('common.brand.tagline') }}</p>
            <h1 class="mt-2 text-2xl font-bold">{{ __('messages.dashboard_heading') }}</h1>
            <p class="mt-3 max-w-2xl text-sm leading-7 text-gray-600">{{ __('messages.dashboard_help') }}</p>
        </section>
    </div></div>
    @endif
</x-app-layout>
