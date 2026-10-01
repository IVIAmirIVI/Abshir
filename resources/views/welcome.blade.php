<x-guest-layout>
    <h1 class="text-xl font-semibold text-brand-primary">{{ __('messages.welcome_heading') }}</h1>
    <p class="mt-3 text-gray-600">{{ __('messages.welcome_help') }}</p>
    <div class="mt-6">
        @auth
            <a class="inline-block rounded-md bg-brand-primary px-4 py-2 text-white" href="{{ route('dashboard') }}">{{ __('messages.open_workspace') }}</a>
        @else
            <a class="inline-block rounded-md bg-brand-primary px-4 py-2 text-white" href="{{ route('login') }}">{{ __('common.actions.login') }}</a>
        @endauth
    </div>
</x-guest-layout>
