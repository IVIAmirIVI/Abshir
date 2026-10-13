<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-bold">{{ __('captain_portal.title') }}</h2></x-slot>
    <x-management.page>
        <div class="min-w-0 space-y-5" data-captain-portal>
            <div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-bold">{{ auth()->user()->name }}</h1><a class="btn btn-outline" href="{{ route('captain.self-information') }}">{{ __('captain_portal.financial_information') }}</a></div>
            <p class="alert text-sm">{{ __('captain_portal.read_only') }}</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-mary-card :title="__('captain_portal.active')" class="min-w-0 border"><p class="text-3xl font-bold"><x-ltr>{{ $metrics->sum() }}</x-ltr></p></x-mary-card>
                @foreach($metrics as $status => $count)<x-mary-card :title="__('orders.statuses.'.$status)" class="min-w-0 border"><p class="text-2xl"><x-ltr>{{ $count }}</x-ltr></p></x-mary-card>@endforeach
            </div>
            <form method="GET" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
                <label>{{ __('captain_portal.view') }}<select name="view" class="select select-bordered w-full"><option value="active" @selected($filters['view'] === 'active')>{{ __('captain_portal.active') }}</option><option value="history" @selected($filters['view'] === 'history')>{{ __('captain_portal.history') }}</option></select></label>
                <label>{{ __('common.terms.branch') }}<select name="branch_id" class="select select-bordered w-full"><option value="">{{ __('captain_portal.all_own_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)($filters['branch_id'] ?? '') === (string)$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                <label>{{ __('captain_portal.status') }}<select name="orders_status" class="select select-bordered w-full"><option value="">{{ __('captain_portal.all_statuses') }}</option>@foreach(\App\Enums\OrderOperationalStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['orders_status'] ?? '') === $status->value)>{{ __('orders.statuses.'.$status->value) }}</option>@endforeach</select></label>
                <label>{{ __('captain_portal.kind') }}<select name="orders_kind" class="select select-bordered w-full"><option value="">{{ __('captain_portal.all_kinds') }}</option>@foreach(['single','shared'] as $kind)<option value="{{ $kind }}" @selected(($filters['orders_kind'] ?? '') === $kind)>{{ __('captain_portal.'.$kind) }}</option>@endforeach</select></label>
                <label>{{ __('reports.from') }}<input type="text" dir="ltr" class="input input-bordered w-full" name="orders_from" value="{{ $filters['orders_from'] ?? '' }}" placeholder="YYYY-MM-DD" maxlength="10"></label>
                <label>{{ __('reports.to') }}<input type="text" dir="ltr" class="input input-bordered w-full" name="orders_to" value="{{ $filters['orders_to'] ?? '' }}" placeholder="YYYY-MM-DD" maxlength="10"></label>
                <label>{{ __('captain_portal.role') }}<select name="orders_role" class="select select-bordered w-full"><option value="">{{ __('captain_portal.all_roles') }}</option>@foreach(['collector','support','former'] as $role)<option value="{{ $role }}" @selected(($filters['orders_role'] ?? '') === $role)>{{ __('captain_portal.'.$role) }}</option>@endforeach</select></label>
                <button class="btn btn-primary self-end">{{ __('reports.apply') }}</button>
            </form>
            @if($errors->any())<ul class="alert alert-error">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
            <p class="text-sm">{{ __('captain_portal.history_help') }}</p>
            <div class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse($page as $order)
                    <x-mary-card class="min-w-0 border" data-portal-order="{{ $order->id }}">
                        <h2 class="break-words text-lg font-bold"><x-ltr>{{ $order->order_number }}</x-ltr></h2>
                        <p class="my-2"><span class="badge badge-outline">{{ __('orders.statuses.'.$order->operational_status) }}</span> · {{ __('captain_portal.'.($order->history_shared ? 'shared' : 'single')) }}</p>
                        <p>{{ __('captain_portal.'.($order->removed_at !== null ? 'former' : ($order->assignment_role === 'PRIMARY_COLLECTION' ? 'collector' : 'support'))) }}</p>
                        <p class="my-2 text-xs"><x-ltr>{{ \App\Support\UiDate::datetime($order->ordered_at) }}</x-ltr></p>
                        <a class="btn btn-primary w-full" href="{{ route('captain.portal.orders.show', $order->id) }}">{{ __('captain_portal.open') }}</a>
                    </x-mary-card>
                @empty<p class="rounded-xl border bg-white p-5">{{ __('captain_portal.empty') }}</p>@endforelse
            </div>
            {{ $page->links() }}
        </div>
    </x-management.page>
</x-app-layout>
