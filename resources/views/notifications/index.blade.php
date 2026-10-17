<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('internal.inbox') }}</h2></x-slot>
    <x-management.page>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p>{{ __('internal.unread') }}: <x-ltr>{{ $unreadCount }}</x-ltr></p>
            @can('manage-authorization')<a class="btn btn-primary" href="{{ route('notifications.create') }}">{{ __('internal.compose') }}</a>@endcan
        </div>
        @if(session('status'))<p role="status" class="alert alert-success">{{ session('status') }}</p>@endif
        @if(!$available)<p role="status" class="alert">{{ __('internal.activation_required') }}</p>
        @else
            <div class="space-y-4">
                @forelse($notices as $notice)
                    @php
                        $workflow = $notice->type === \App\Notifications\CaptainBenefitWorkflowNotice::class;
                        $text = $workflow ? app(\App\Services\CaptainBenefitNotificationService::class)->text($notice->data) : $notice->data;
                    @endphp
                    <x-management.record>
                        <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">{{ $text['title'] }}</h3><span class="badge">{{ __($notice->read_at ? 'internal.read' : 'internal.unread') }}</span></div>
                        <p class="whitespace-pre-wrap break-words">{{ $text['body'] }}</p>
                        <x-ltr>{{ \App\Support\UiDate::datetime($notice->created_at) }}</x-ltr>
                        <div class="flex flex-wrap gap-3">
                            @if($workflow || filled($notice->data['order_id'] ?? null))<a class="link" href="{{ route('notifications.open', $notice->id) }}">{{ __($workflow ? 'internal.open_request' : 'internal.open') }}</a>@endif
                            @if(!$notice->read_at)<form method="POST" action="{{ route('notifications.read', $notice->id) }}">@csrf<button class="btn btn-sm btn-outline">{{ __('internal.mark_read') }}</button></form>@endif
                        </div>
                    </x-management.record>
                @empty<p role="status">{{ __('internal.empty') }}</p>@endforelse
            </div>
            {{ $notices->links() }}
        @endif
    </x-management.page>
</x-app-layout>
