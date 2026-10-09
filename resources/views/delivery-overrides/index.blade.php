<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('admin.approvals_title', ['count' => $pendingCount]) }}</h2></x-slot>
    <div class="mx-auto max-w-6xl space-y-4 px-4 py-8">
        @forelse($requests as $request)
            <article class="min-w-0 break-words rounded bg-white p-5 shadow space-y-2">
                <div class="flex flex-wrap justify-between gap-2">
                    <strong><x-ltr>{{ $request->order->order_number }}</x-ltr> · {{ $request->fromArea->path() }}</strong>
                    <span>{{ \App\Support\UiLabels::label('admin.override_statuses', $request->status) }}</span>
                </div>
                <p>{{ __('admin.suggested_fee') }} <x-money :amount="$request->suggested_unit_fee" /> → {{ __('admin.requested_fee') }} <x-money :amount="$request->requested_unit_fee" /> (<x-ltr>{{ $request->difference_percent }}%</x-ltr>)</p>
                <p>{{ $request->reason }} · {{ __('admin.requested_by') }} {{ $request->requester?->name }}</p>
                <p>{{ __('admin.requested_at') }} <x-ltr>{{ ($request->requested_at === null ? null : \App\Support\UiDate::datetime($request->requested_at)) }}</x-ltr></p>
                @if($request->resolved_at)
                    <p>{{ __('admin.resolved_by') }} {{ $request->resolver?->name }} · {{ __('admin.resolved_at') }} <x-ltr>{{ \App\Support\UiDate::datetime($request->resolved_at) }}</x-ltr></p>
                    <p>{{ $request->resolution_reason }}</p>
                @endif
                @if($request->status === \App\Enums\DeliveryOverrideStatus::Pending)
                    <form method="POST" action="{{ route('delivery-overrides.resolve',$request) }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf @method('PATCH')
                        <x-text-input class="min-w-0 max-w-full" aria-label="{{ __('admin.ui.resolution_note') }}" name="resolution_reason" :placeholder="__('admin.ui.resolution_note')"/>
                        <button name="decision" value="approve" class="app-button rounded bg-green-700 px-3 py-2 text-white">{{ __('admin.ui.approve') }}</button>
                        <button name="decision" value="reject" class="app-button rounded bg-red-700 px-3 py-2 text-white">{{ __('admin.ui.reject') }}</button>
                    </form>
                @endif
            </article>
        @empty
            <p>{{ __('admin.no_approvals') }}</p>
        @endforelse
        {{ $requests->links() }}
    </div>
</x-app-layout>
