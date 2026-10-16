@can('order_shop_returns.view')
@can('captain_financials.view')
@if(\Illuminate\Support\Facades\Schema::hasTable('customer_return_cases'))
@php($returnLiabilities = app(\App\Services\CustomerReturnService::class)->captainLiabilities(auth()->user(), $captain))
<section class="min-w-0 space-y-3 rounded-xl border border-amber-200 bg-white p-4" data-captain-return-liabilities>
    <h2 class="font-semibold">{{ __('returns.liabilities') }}</h2><p class="text-sm text-gray-600">{{ __('returns.liability_help') }}</p><p class="text-xs">{{ __('returns.recent_liabilities') }}</p>
    @forelse($returnLiabilities as $liability)
    <div class="space-y-1 rounded-lg border p-3 text-sm" data-return-responsibility="{{ $liability->id }}">
        <p>{{ $liability->branch_name }} · <x-ltr>{{ $liability->currency_code }}</x-ltr></p>
        <p>{{ __('returns.recorded_responsibility') }}: <x-decimal :value="$liability->amount" /></p>
        <p>{{ __('returns.unfunded') }}: <x-decimal :value="$liability->unfunded_amount" /></p>
        <p>{{ __('returns.funded') }}: <x-decimal :value="$liability->funded_amount" /></p>
        <p>{{ __('returns.repaid') }}: <x-decimal :value="$liability->repaid_amount" /></p>
        <p class="font-semibold">{{ __('returns.outstanding') }}: <x-decimal :value="$liability->outstanding" /></p>
        <a class="underline" href="{{ route('orders.workflow', $liability->order_id) }}">{{ __('orders.workflow.title') }} #{{ $liability->order_id }}</a>
    </div>
    @empty<p class="text-sm">{{ __('returns.empty') }}</p>@endforelse
</section>
@endif
@endcan
@endcan
