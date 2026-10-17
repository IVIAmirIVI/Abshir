@if(app(\App\Services\SharedPurchaseBoundary::class)->hasSelectedSharedContract($invoice->order))
    @php
        $sharedSource = \App\Models\OrderFinancialSourceSnapshot::where('invoice_id', $invoice->id)->with('lines')->first();
        $sharedSelection = $invoice->order->captainSelections()->latest('id')->first();
        $sharedIds = $sharedSource ? $sharedSource->lines->pluck('order_captain_id')->all() : $sharedSelection->assignment_ids;
        $sharedCollector = $sharedSource?->primary_order_captain_id ?? $sharedSelection->collecting_order_captain_id;
        $sharedParticipants = \App\Models\OrderCaptain::whereIn('id', $sharedIds)->with('captain.user')->orderBy('id')->get();
    @endphp
    <div class="my-3 space-y-1 text-sm" data-shared-invoice-captains>
        <p class="font-semibold">{{ __('shared_orders.participants') }}</p>
        @foreach($sharedParticipants as $participant)
            <p>{{ $participant->captain->user->name }} @if($participant->id === $sharedCollector) · {{ __('shared_orders.collector') }} @endif</p>
        @endforeach
    </div>
@endif