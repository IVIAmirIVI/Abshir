@php
    $actions = [];
    if (auth()->user()->can('captains.update')) {
        if ($summary['state'] === 'PROPOSED') { $actions[] = 'SUBMIT'; }
        if (in_array($summary['state'], ['PROPOSED','PENDING'], true)) { $actions[] = 'CANCEL'; }
    }
    if (auth()->user()->can(app(\App\Services\CaptainBenefitReviewService::class)->permission($proposal))) {
        if ($summary['state'] === 'PENDING') {
            if (!in_array(auth()->id(), [$proposal->created_by_user_id, $proposal->recorded_by_user_id, $reviews->firstWhere('action', 'SUBMIT')?->actor_id], true)) { $actions[] = 'APPROVE'; }
            $actions[] = 'REJECT';
        }
        if ($summary['state'] === 'APPROVED_UNPAID') { $actions[] = 'REVERSE'; }
    }
@endphp
<section class="flex flex-wrap gap-4">
    @foreach($actions as $action)
        <form method="POST" action="{{ route('captains.requests.review', [$captain, $proposal]) }}" class="space-y-2 w-full sm:w-auto">
            @csrf
            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="action" value="{{ $action }}">
            @if(in_array($action, ['REJECT','REVERSE'], true))
                <label class="block">{{ __('captain_requests.review_reason') }}<textarea name="reason" required maxlength="2000" class="textarea textarea-bordered block w-full"></textarea></label>
            @endif
            <button type="submit" class="btn btn-outline">{{ __('captain_requests.actions.'.$action) }}</button>
        </form>
    @endforeach
    @if($summary['state'] === 'PENDING' && auth()->user()->can(app(\App\Services\CaptainBenefitReviewService::class)->permission($proposal)) && !in_array('APPROVE', $actions, true))<p>{{ __('captain_requests.self_approval') }}</p>@endif
    @if(in_array($summary['state'], ['PARTIALLY_PAID','FULLY_PAID'], true))<p>{{ __('captain_requests.paid_reversal_blocked') }}</p>@endif
</section>
