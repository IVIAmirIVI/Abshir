<x-app-layout>
    <x-slot name="header"><h2 class="min-w-0 max-w-full break-words text-xl font-semibold">{{ __('captain_requests.kinds.'.$proposal->kind) }} — {{ $captain->user->name }}</h2></x-slot>
    <x-management.page class="captain-request-page w-full">
        <x-captains.navigation :captain="$captain" />
        <p class="alert">{{ __('captain_requests.disclaimer') }}</p>
        @if(session('status'))<p role="status" class="alert alert-success">{{ session('status') }}</p>@endif
        @if(session('warning'))<p role="alert" class="alert alert-warning">{{ session('warning') }}</p>@endif
        @if($errors->any())<ul role="alert" class="alert alert-error">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <x-management.record>
            <p>{{ __('captain_requests.states.'.$summary['state']) }} · {{ $proposal->branch->name }} · <x-money :amount="$proposal->amount" :currency="$proposal->currency->code" /></p>
            <p>{{ $proposal->description }}</p>
            <p>{{ __('captain_requests.creator') }}: {{ $proposal->createdBy->name }} · <x-ltr>{{ \App\Support\UiDate::datetime($proposal->created_at) }}</x-ltr></p>
            @if($proposal->expense_date)<p>{{ __('captain_requests.categories.'.$proposal->category) }} · <x-ltr>{{ $proposal->expense_date->format('Y-m-d') }}</x-ltr></p>@endif
            @if($proposal->order)@can('orders.view')<a class="link" href="{{ route('orders.show', $proposal->order) }}"><x-ltr>{{ $proposal->order->order_number }}</x-ltr></a>@endcan @endif
        </x-management.record>
        @if($proposal->nextVersion)
            <a class="btn btn-outline" href="{{ route('captains.requests.show', [$captain, $proposal->nextVersion]) }}">{{ __('captain_requests.latest') }}</a>
        @elseif($summary['state'] === 'PROPOSED')
            @can('captains.update')<section class="min-w-0 rounded-xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5">@include('captain-requests._form')</section>@endcan
        @endif
        @if($reviewAvailable && !$proposal->nextVersion)
            @include('captain-requests._review')
        @endif
        @if(in_array($summary['state'], ['APPROVED_UNPAID','PARTIALLY_PAID','FULLY_PAID'], true))
            <p>{{ __('financial.ui.already_paid') }}: <x-money :amount="$summary['paid']" :currency="$proposal->currency->code" /> · {{ __('financial.ui.remaining') }}: <x-money :amount="$summary['remaining']" :currency="$proposal->currency->code" /></p>
            @can('captain_financials.view')@can('captain_receivable_payouts.create')
                @if($summary['remaining'] !== '0.0000')<a class="btn btn-outline" href="{{ route('captains.financials.show', [$captain, 'branch_id' => $proposal->branch_id, 'currency_id' => $proposal->currency_id]) }}#captain-payout">{{ __('captain_requests.open_payout') }}</a>@endif
            @endcan @endcan
        @endif
        @if($reviews->isNotEmpty())
            <section class="space-y-2"><h3 class="font-semibold">{{ __('captain_requests.review_history') }}</h3>
                @foreach($reviews as $review)<p>{{ __('captain_requests.actions.'.$review->action) }} · {{ $review->actor->name }} · <x-ltr>{{ \App\Support\UiDate::datetime($review->recorded_at) }}</x-ltr> · {{ $review->reason }}</p>@endforeach
            </section>
        @endif
        <section class="space-y-3">
            <h3 class="font-semibold">{{ __('captain_requests.history') }}</h3>
            @foreach($history as $revision)
                <x-management.record>
                    <p>{{ __('captain_requests.version') }} <x-ltr>{{ $revision->version }}</x-ltr> · <x-money :amount="$revision->amount" :currency="$proposal->currency->code" /></p>
                    <p>{{ $revision->description }}</p>
                    @if($revision->expense_date)<p>{{ __('captain_requests.categories.'.$revision->category) }} · <x-ltr>{{ $revision->expense_date->format('Y-m-d') }}</x-ltr></p>@endif
                    <p>{{ $revision->revision_note }}</p>
                    <p>{{ __('captain_requests.recorded_by') }}: {{ $revision->recordedBy->name }} · <x-ltr>{{ \App\Support\UiDate::datetime($revision->recorded_at) }}</x-ltr></p>
                    @if($revision->evidence_path)@can('captain_financial_evidence.download')<a class="link" href="{{ route('captains.requests.evidence', [$captain, $revision]) }}">{{ __('financial.ui.evidence') }}</a>@endcan @endif
                </x-management.record>
            @endforeach
        </section>
    </x-management.page>
</x-app-layout>
