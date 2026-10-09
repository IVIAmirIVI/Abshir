<a href="{{ route('dashboard') }}" @class(['app-nav-link', 'is-active' => request()->routeIs('dashboard')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif>
    <x-mary-icon name="o-squares-2x2" class="h-5 w-5 shrink-0" />{{ __('admin.navigation.dashboard') }}
</a>
@foreach($groups as $group)
    <details @class(['app-nav-group', 'has-active-route' => collect($group['items'])->contains('active', true)]) x-data="{ expanded: false }" @toggle="expanded = $el.open" wire:ignore.self>
        <summary aria-expanded="false" :aria-expanded="expanded.toString()">{{ $group['label'] }}<x-mary-icon name="o-chevron-down" class="h-4 w-4 shrink-0" /></summary>
        <div class="space-y-1 pb-3">
            @foreach($group['items'] as $link)
                <a href="{{ route($link['route']) }}" @class(['app-nav-link', 'is-active' => $link['active']]) @if($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
            @endforeach
        </div>
    </details>
@endforeach
<div class="app-nav-account">
    <a class="app-nav-link" href="{{ route('profile.edit') }}" @if(request()->routeIs('profile.*')) aria-current="page" @endif><x-mary-icon name="o-user-circle" class="h-5 w-5 shrink-0" />{{ __('admin.navigation.profile') }}</a>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="app-nav-link w-full text-start"><x-mary-icon name="o-arrow-right-start-on-rectangle" class="h-5 w-5 shrink-0" />{{ __('common.actions.logout') }}</button></form>
</div>
