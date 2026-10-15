<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\UiLabels::direction() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ __('common.brand.name') }}</title>
        @vite(['resources/css/app.css', 'resources/css/mary.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-brand-canvas text-gray-900">
        <div class="app-shell" x-data="{ collapsed: false }" :class="{ 'app-shell-collapsed': collapsed }" data-app-shell>
            <a href="#app-main" class="app-skip-link">{{ __('shell.skip_content') }}</a>
            @include('layouts.navigation')
            <div class="app-workspace">
                <header class="app-topbar">
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button" class="app-icon-button inline-flex lg:hidden" @click="$refs.mobileNavigation.showModal()" aria-controls="mobile-navigation" aria-haspopup="dialog" aria-label="{{ __('admin.navigation.open_menu') }}">
                            <x-mary-icon name="o-bars-3" class="h-5 w-5" />
                        </button>
                        <button type="button" class="app-icon-button hidden lg:inline-flex" @click="collapsed = !collapsed" :aria-expanded="(!collapsed).toString()" :aria-label="collapsed ? @js(__('shell.expand')) : @js(__('shell.collapse'))" aria-controls="desktop-navigation">
                            <x-mary-icon name="o-bars-3" class="h-5 w-5" />
                        </button>
                        <a href="{{ route('dashboard') }}" class="app-topbar-brand shrink-0"><x-application-logo /></a>
                    </div>
                    <div class="flex min-w-0 items-center gap-2">
                        <livewire:shell.navigation-finder />
                        <a href="{{ route('profile.edit') }}" class="app-account-link" aria-label="{{ __('shell.account') }}">
                            <span class="hidden max-w-44 truncate text-sm font-medium sm:block">{{ auth()->user()->name }}</span>
                            <x-mary-icon name="o-user-circle" class="h-6 w-6" />
                        </a>
                    </div>
                </header>
                @isset($header)
                    <div class="app-page-heading">
                        <nav class="mb-3 text-xs text-gray-600" aria-label="{{ __('shell.breadcrumb') }}"><a href="{{ route('dashboard') }}" class="hover:text-brand-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary">{{ __('admin.navigation.dashboard') }}</a><span class="mx-2" aria-hidden="true">/</span><span aria-current="page">{{ trim(strip_tags((string) $header)) }}</span></nav>
                        {{ $header }}
                    </div>
                @endisset
                <main id="app-main" class="app-main" tabindex="-1">{{ $slot }}</main>
            </div>
        </div>
        @livewireScripts
    </body>
</html>
