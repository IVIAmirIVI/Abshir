<nav x-data="{ open: false }" class="bg-white border-b border-gray-200" aria-label="{{ __('admin.navigation.dashboard') }}">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between gap-3 min-h-16 py-2">
            <a class="shrink-0" href="{{ route('dashboard') }}"><x-application-logo /></a>
            <div class="hidden lg:flex items-center gap-4 min-w-0" data-navigation="desktop">
                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('admin.navigation.dashboard') }}</x-nav-link>
                @include('layouts.navigation-links', ['mobile' => false])
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger"><button type="button" :aria-expanded="open.toString()" class="max-w-40 truncate rounded-md px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-primary">{{ Auth::user()->name }}</button></x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">{{ __('admin.navigation.profile') }}</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="block w-full px-4 py-2 text-start text-sm hover:bg-gray-100">{{ __('common.actions.logout') }}</button></form>
                    </x-slot>
                </x-dropdown>
            </div>
            <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-controls="mobile-navigation" class="lg:hidden rounded-md p-2 text-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary" aria-label="{{ __('admin.navigation.open_menu') }}">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path x-show="!open" stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/><path x-show="open" stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <div id="mobile-navigation" :class="{'block': open, 'hidden': !open}" class="hidden lg:hidden" data-navigation="mobile">
        <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('admin.navigation.dashboard') }}</x-responsive-nav-link>
        @include('layouts.navigation-links', ['mobile' => true])
        <div class="border-t border-gray-200 py-3">
            <div class="px-4"><div class="font-medium break-words">{{ Auth::user()->name }}</div><x-ltr class="block break-all text-sm text-gray-500">{{ Auth::user()->email }}</x-ltr></div>
            <x-responsive-nav-link :href="route('profile.edit')">{{ __('admin.navigation.profile') }}</x-responsive-nav-link>
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="block w-full px-4 py-2 text-start text-sm">{{ __('common.actions.logout') }}</button></form>
        </div>
    </div>
</nav>
