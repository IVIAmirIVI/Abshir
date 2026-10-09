@if(\Illuminate\Support\Facades\Schema::hasTable('order_captain_selections'))
    @php($selections = $order->captainSelections()->with('actor')->reorder('id', 'desc')->limit(25)->get())
    @if($selections->isNotEmpty())
        @php($selectionAssignments = $order->captainAssignments()->with('captain.user')->whereIn('id', $selections->pluck('assignment_ids')->flatten()->unique())->get()->keyBy('id'))
        <details class="rounded-xl border bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('shared_orders.selection_history') }}</summary><div class="mt-3 space-y-3">
            @foreach($selections as $selection)<article class="space-y-1 border-b pb-3 text-sm"><p><x-ltr>{{ $selection->selected_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i:s') }}</x-ltr> · {{ $selection->actor?->name }}</p>@foreach($selection->assignment_ids as $assignmentId)<p>{{ $selectionAssignments->get($assignmentId)?->captain?->user?->name }} @if($assignmentId === $selection->collecting_order_captain_id) · {{ __('shared_orders.collector') }}@endif</p>@endforeach@if($selection->reason)<p class="break-words">{{ $selection->reason }}</p>@endif</article>@endforeach
        </div></details>
    @endif
@endif
