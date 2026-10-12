<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('captain_requests.title') }} — {{ $captain->user->name }}</h2></x-slot>
    <x-management.page>
        <p class="alert">{{ __('captain_requests.disclaimer') }}</p>
        @if(session('status'))<p role="status" class="alert alert-success">{{ session('status') }}</p>@endif
        @if($errors->any())<ul role="alert" class="alert alert-error">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        @if(!$available)
            <p role="status">{{ __('captain_requests.activation_required') }}</p>
        @else
            @can('captains.update')
                <section class="rounded-xl bg-base-100 p-5 shadow">
                    @include('captain-requests._form')
                </section>
            @endcan
            <section class="space-y-3">
                @forelse($proposals as $row)
                    <x-management.record>
                        <div class="flex flex-wrap gap-3 justify-between">
                            <h3 class="font-semibold">{{ __('captain_requests.kinds.'.$row->kind) }} · {{ __('captain_requests.states.'.app(\App\Services\CaptainBenefitReviewService::class)->summary($row)['state']) }}</h3>
                            <x-money :amount="$row->amount" :currency="$row->currency->code" />
                        </div>
                        <p>{{ $row->branch->name }} · {{ $row->description }}</p>
                        <p class="text-sm">{{ __('captain_requests.creator') }}: {{ $row->createdBy->name }} · <x-ltr>{{ \App\Support\UiDate::datetime($row->created_at) }}</x-ltr></p>
                        <a class="link" href="{{ route('captains.requests.show', [$captain, $row]) }}">{{ __('captain_requests.history') }} · {{ __('captain_requests.version') }} <x-ltr>{{ $row->version }}</x-ltr></a>
                    </x-management.record>
                @empty<p role="status">{{ __('captain_requests.empty') }}</p>@endforelse
                {{ $proposals->links() }}
            </section>
        @endif
    </x-management.page>
</x-app-layout>
