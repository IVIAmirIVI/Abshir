@php($navigationGroups = \App\Support\ApplicationNavigation::groups(auth()->user()))
<aside id="desktop-navigation" class="app-sidebar hidden lg:flex" aria-label="{{ __('shell.navigation') }}">
    <div class="app-sidebar-brand"><a href="{{ route('dashboard') }}"><x-application-logo /></a></div>
    <nav class="app-sidebar-scroll" data-navigation="desktop" aria-label="{{ __('shell.navigation') }}">
        @include('layouts.navigation-links', ['groups' => $navigationGroups, 'surface' => 'desktop'])
    </nav>
</aside>
<dialog id="mobile-navigation" x-ref="mobileNavigation" class="app-mobile-drawer lg:hidden" aria-labelledby="mobile-navigation-title" @resize.window="if (window.innerWidth >= 1024 && $el.open) $el.close()" @click="if ($event.target === $el && ($event.clientX < $el.getBoundingClientRect().left || $event.clientX > $el.getBoundingClientRect().right)) $el.close()">
    <div class="flex items-center justify-between gap-3 border-b border-stone-200 px-5 py-4">
        <h2 id="mobile-navigation-title"><x-application-logo /></h2>
        <button type="button" class="app-icon-button inline-flex" @click="$refs.mobileNavigation.close()" aria-label="{{ __('admin.navigation.close_menu') }}"><x-mary-icon name="o-x-mark" class="h-5 w-5" /></button>
    </div>
    <nav class="app-sidebar-scroll" data-navigation="mobile" aria-label="{{ __('shell.navigation') }}">
        @include('layouts.navigation-links', ['groups' => $navigationGroups, 'surface' => 'mobile'])
    </nav>
</dialog>
