<x-app-layout><x-slot name="header"><h2 class="break-all text-xl font-semibold">{{ __('orders.workflow.summary') }} · <x-ltr>{{ $order->order_number }}</x-ltr></h2></x-slot>
<div class="mx-auto min-w-0 max-w-5xl space-y-5 px-4 py-6 sm:px-6" data-order-summary>
@php($orderMutable = false)
@php($summaryOnly = true)
@php($historyOnly = true)
@php($pricingPreview = null)
@if(session('status'))<p class="rounded-lg bg-green-50 p-3">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<a class="inline-flex rounded-lg bg-brand-primary px-5 py-3 font-semibold text-white" href="{{ route('orders.workflow', $order) }}">{{ __('orders.workflow.return') }}</a>
@include('orders._summary')
@foreach($order->shops as $orderShop)<section class="space-y-3 rounded-xl border bg-white p-4"><h3 class="break-words font-semibold">{{ $orderShop->shop_name_snapshot }}</h3><p class="text-sm">{{ ($orderShop->business_classification_snapshot ? \App\Support\UiLabels::label('orders.shop_classifications', $orderShop->business_classification_snapshot) : null) ?? __('orders.ui_legacy_class') }}</p>@include('orders._shop-items-summary')@include('orders._shop-facts')</section>@endforeach
@include('orders._delivery-pricing')
<section class="space-y-3 rounded-xl border bg-white p-4"><h3 class="font-semibold">{{ __('orders.workflow.captain') }}</h3>@foreach($order->captainAssignments as $assignment)<p>{{ $assignment->captain?->user?->name }} · {{ \App\Support\UiLabels::label('orders.captain_roles', $assignment->assignment_role) }} · <x-ltr>{{ $assignment->assigned_at?->format('Y-m-d H:i') }}</x-ltr>@if($assignment->removed_at) · {{ __('orders.ui_historical_assignment') }} <x-ltr>{{ $assignment->removed_at->format('Y-m-d H:i') }}</x-ltr>@endif</p>@can('captain_financials.view')<a href="{{ route('captains.financials.show', $assignment->captain) }}" class="block text-sm underline">{{ __('captains.ui_receipts_payouts_and_outstanding_receivables') }}</a>@endcan @endforeach</section>
@can('invoices.view')@include('orders._invoices')@endcan
<details class="rounded-xl border bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('orders.ui_history') }}</summary><div class="mt-3 space-y-2"><p class="text-sm text-gray-600">{{ __('orders.ui_history_scope') }}</p>@foreach($order->statusHistory as $history)<p class="text-sm"><x-ltr>{{ $history->changed_at->format('Y-m-d H:i') }}</x-ltr> · {{ $history->previous_status ? \App\Support\UiLabels::label('orders.statuses', $history->previous_status) : __('orders.ui_created') }} → {{ \App\Support\UiLabels::label('orders.statuses', $history->new_status) }} · {{ $history->reason }}</p>@endforeach</div></details>
@include('orders._operational-history')
<details class="rounded-xl border bg-white p-4" @if($workflow['exceptional']) open @endif><summary class="cursor-pointer font-semibold">{{ __('orders.workflow.advanced') }}</summary><div class="mt-4 space-y-4">@include('orders._phase9c2', ['exceptionsOnly' => true])@include('orders._phase85')<details class="rounded-lg border p-3"><summary class="cursor-pointer">{{ __('orders.ui_external_bill_payments') }}</summary><div class="mt-3">@include('orders._bill-payments')</div></details></div></details>
</div></x-app-layout>
