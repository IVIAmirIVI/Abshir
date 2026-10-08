@if($cancellationUi['visible'])
<details class="rounded-xl border border-red-200 bg-white p-4" data-workflow-cancellation>
    <summary class="cursor-pointer font-semibold text-red-700">{{ __('orders.ui_cancel_order_action') }}</summary>
    <div class="mt-3 space-y-3">
    @if(!$cancellationUi['allowed'])
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm">{{ $cancellationUi['problem'] }}</p>
    @else
        <p class="text-sm">{{ $cancellationUi['afterPurchase'] ? __('orders.ui_returns_help') : __('orders.ui_before_help') }}</p>
        <form method="POST" action="{{ $cancellationUi['afterPurchase'] ? route('orders.cancellation-intent.store', $order) : route('orders.cancel', $order) }}" class="grid gap-3 sm:grid-cols-2"
            x-data="{ submitting: false }" data-confirm="{{ __('orders.ui_confirm_sensitive_action') }}"
            x-on:submit="if (submitting || !confirm($el.dataset.confirm)) { $event.preventDefault(); } else { submitting = true; }">
            @csrf
            @if($cancellationUi['afterPurchase'])<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@else<input type="hidden" name="classification" value="BEFORE_PURCHASE">@endif
            <label>{{ __('financial.ui.reason') }}<select name="reason" required class="mt-1 w-full rounded-lg border-gray-300">
                <option value="">{{ __('orders.ui_cancel_reason') }}</option>
                @foreach(\App\Enums\OrderCancellationReason::cases() as $reason)<option value="{{ $reason->value }}">{{ \App\Support\UiLabels::label('orders.cancellation_reasons', $reason) }}</option>@endforeach
            </select></label>
            <label>{{ __('common.fields.notes') }}<textarea name="notes" maxlength="3000" dir="auto" class="mt-1 w-full rounded-lg border-gray-300"></textarea></label>
            <x-primary-button x-bind:disabled="submitting">{{ $cancellationUi['afterPurchase'] ? __('orders.ui_start_return') : __('orders.ui_cancel_before') }}</x-primary-button>
        </form>
    @endif
    </div>
</details>
@endif
