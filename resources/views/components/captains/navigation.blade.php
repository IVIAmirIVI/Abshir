@props(['captain', 'compact' => false])
@php
    $actor = auth()->user();
    $currentAccess = $actor->canAccessCaptain($captain);
    $profile = $actor->can('captains.view') && $currentAccess;
    $information = $currentAccess && app(\App\Services\CaptainInformationQuery::class)->canView($actor);
    // Historical pages keep their existing branch-scoped access after a transfer.
    // These checks only hide dead links; controllers/services still authorize requests.
    $requests = $actor->can('captains.view') && ($currentAccess || (
        \Illuminate\Support\Facades\Schema::hasTable('captain_benefit_requests') &&
        \App\Models\CaptainBenefitRequest::where('captain_id', $captain->id)
            ->whereIn('branch_id', $actor->availableBranches()->select('branches.id'))->exists()
    ));
    $financial = $actor->can('captain_financials.view') && ($currentAccess ||
        \App\Models\FinancialEntry::whereHas('account.owner', fn($query) => $query->where('owner_type', 'CAPTAIN')->where('captain_id', $captain->id))
            ->whereHas('transaction', fn($query) => $query->whereIn('branch_id', $actor->availableBranches()->select('branches.id')))->exists()
    );
    $items = array_filter([
        'profile' => $profile ? ['captains.show', ['captains.show', 'captains.edit']] : null,
        'information' => $information ? ['captains.information', ['captains.information']] : null,
        'financial' => $financial ? ['captains.financials.show', ['captains.financials.*', 'captains.manual-deposit-adjustments.*']] : null,
        'requests' => $requests ? ['captains.requests.index', ['captains.requests.*']] : null,
    ]);
@endphp
<nav aria-label="{{ __('captains.navigation.label') }} — {{ $captain->user->name }}" data-captain-navigation="{{ $captain->id }}" @class(['captain-feature-navigation', 'captain-feature-navigation--compact' => $compact])>
    @unless($compact)
        @can('captains.view')<a class="captain-feature-link" href="{{ route('captains.index') }}">{{ __('captains.navigation.index') }}</a>@endcan
    @endunless
    @foreach($items as $label => [$routeName, $patterns])
        <a href="{{ route($routeName, $captain) }}" @class(['captain-feature-link', 'is-active' => request()->routeIs(...$patterns)]) @if(request()->routeIs(...$patterns)) aria-current="page" @endif>{{ __('captains.navigation.'.$label) }}</a>
    @endforeach
</nav>
