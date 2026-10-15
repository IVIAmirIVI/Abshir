<x-app-layout>
    <x-slot name="header"><div class="min-w-0 max-w-full space-y-1"><h2 class="break-words text-xl font-semibold">{{ __('captain_requests.title') }}</h2><p class="break-words text-sm text-gray-600">{{ $captain->user->name }}</p></div></x-slot>
    <x-management.page class="captain-request-page w-full">
        <x-captains.navigation :captain="$captain" />
        <p class="alert">{{ __('captain_requests.disclaimer') }}</p>
        @if(session('status'))<p role="status" class="alert alert-success">{{ session('status') }}</p>@endif
        @if($errors->any())<ul role="alert" class="alert alert-error">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        @if(!$available)
            <p role="status">{{ __('captain_requests.activation_required') }}</p>
        @else
            @if($creationKinds !== [])
                <section id="captain-benefit-create" aria-labelledby="captain-benefit-create-title" class="min-w-0 space-y-4 rounded-xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5">
                    <h3 id="captain-benefit-create-title" class="text-lg font-semibold">{{ __('captain_requests.new_request') }}</h3>
                    @include('captain-requests._form')
                </section>
            @else
                <p role="status" class="rounded-lg border border-stone-200 bg-white p-4 text-sm">{{ __('captain_requests.read_only_access') }}</p>
            @endif
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
