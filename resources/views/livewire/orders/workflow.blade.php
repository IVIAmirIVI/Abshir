<div data-theme="abshir" class="mary-surface order-ux mx-auto min-w-0 max-w-6xl space-y-5 px-4 py-6 sm:px-6 [&_input]:min-w-0 [&_input]:max-w-full [&_select]:min-w-0 [&_select]:max-w-full [&_textarea]:min-w-0 [&_textarea]:max-w-full" data-order-workflow>
    @php($orderMutable = $workflow['mutable'])
    @php($canFinalizePricing = $workflow['canFinalizePricing'])
    @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-3">{{ session('status') }}</p>@endif
    @if($actionStatus)<p role="status" class="rounded-lg bg-green-50 p-3">{{ $actionStatus }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <section id="workflow-overview" class="workflow-overview" data-workflow-overview>
        <header class="workflow-overview-heading"><div class="min-w-0"><p class="text-xs font-semibold text-gray-500">{{ __('orders.workflow.title') }}</p><h2 class="mt-1 break-words text-xl font-bold text-brand-primary"><x-ltr>{{ $order->order_number }}</x-ltr></h2></div><x-orders.indicator :tone="$workflow['exceptional'] ? 'blocked' : ($workflow['delivered'] ? 'complete' : 'neutral')">{{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status) }}</x-orders.indicator></header>
        <p class="workflow-overview-meta text-sm text-gray-600"><strong>{{ $order->customer_name_snapshot }}</strong><x-ltr>{{ $order->customer_phone_snapshot }}</x-ltr><span>{{ $order->branch->name }} · {{ $order->area_path_snapshot }}</span></p>
        <div class="workflow-overview-footer">
            @if($workflow['primary'])<p class="workflow-overview-collector text-sm"><span class="text-gray-600">{{ __('order_design.collector') }}:</span> <strong>{{ $workflow['primary']->captain?->user?->name }}</strong></p>@endif
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2"><a href="{{ route('orders.show', $order) }}" class="text-sm font-semibold text-brand-primary underline">{{ __('orders.workflow.summary') }}</a>@can('orders.create')<a href="{{ route('orders.create') }}" class="text-sm underline">{{ __('orders.workflow.create_another') }}</a>@endcan</div>
        </div>
    </section>
    <p wire:loading role="status" class="text-sm font-medium text-brand-primary">{{ __('order_design.working') }}</p>
    @if($action = $workflow['nextAction'])
    <section id="workflow-next-action" class="workflow-next-action" aria-labelledby="workflow-next-action-title" data-workflow-next-action="{{ $action['kind'] }}">
        <div class="workflow-next-action-copy">
            <p class="workflow-next-action-label">{{ __('order_execution.next') }}</p>
            <h3 id="workflow-next-action-title">{{ match($action['kind']) {
                'status' => __('orders.workflow.transitions.'.$workflow['next']->value),
                'items', 'purchase', 'receipt', 'pickup' => __('order_execution.'.$action['kind']),
                'pricing' => __('orders.ui_create_pricing'),
                default => __('orders.workflow.'.$action['panel']),
            } }}</h3>
            @if(isset($action['shop_id']))<p>{{ $order->shops->firstWhere('id', $action['shop_id'])?->shop_name_snapshot }}</p>@endif
            @if(!$action['allowed'])<p>{{ $workflow['transitionProblem'] ?? __('order_execution.waiting') }}</p>
            @elseif($action['kind'] === 'status')<p>{{ __('order_execution.status_explicit') }}</p>
            @elseif($action['panel'] === $activePanel)<p>{{ __('order_execution.current_panel_help') }}</p>@endif
        </div>
        @if($action['allowed'])
        @if($action['kind'] === 'status')
            <x-orders.action type="button" wire:click="advance" wire:loading.attr="disabled">{{ __('orders.workflow.transitions.'.$workflow['next']->value) }}</x-orders.action>
        @elseif($action['panel'] !== $activePanel)<x-orders.action type="button" wire:click="openStep('{{ $action['panel'] }}')">{{ __('orders.workflow.'.$action['panel']) }}</x-orders.action>@endif
        @endif
    </section>
    @endif
    <nav data-order-stepper aria-label="{{ __('orders.workflow.title') }}" class="grid grid-cols-2 gap-2 rounded-xl border bg-white p-3 sm:grid-cols-4">
    @if($workflow['exceptional'])<span aria-current="step" class="rounded-lg bg-amber-50 p-3 font-semibold">{{ __('orders.workflow.exception') }}</span>@else
        @foreach($workflow['steps'] as $step => $done)<button type="button" wire:click="openStep('{{ $step }}')" @if($activePanel === $step) aria-current="step" @endif @class(['min-w-0 rounded-lg border px-3 py-2 text-start text-sm focus:outline-none focus:ring-2 focus:ring-brand-primary', 'border-brand-primary bg-green-50 font-semibold' => $activePanel === $step, 'border-gray-100' => $activePanel !== $step])><x-orders.step-label :title="__('orders.workflow.'.$step)" :current="$activePanel === $step" :done="$done" /><span class="text-xs text-gray-500">{{ $done ? __('orders.workflow.done') : ($activePanel === $step ? __('orders.workflow.current') : __('orders.workflow.pending')) }}</span></button>@endforeach
    @endif
    </nav>
    <x-orders.section id="workflow-active-panel" aria-live="polite">
        <h2 class="text-lg font-bold text-brand-primary">{{ __('orders.workflow.'.$activePanel) }}</h2>
        @if(!$workflow['mutable'] && in_array($activePanel, ['summary','confirm','captain','shops']))<x-orders.indicator>{{ __('order_design.readonly') }}</x-orders.indicator><p class="rounded-lg bg-stone-50 p-3 text-sm">{{ __('orders.workflow.locked') }} <a href="{{ route('orders.show', $order) }}" class="underline">{{ __('orders.workflow.advanced') }}</a></p>@endif
        @if(in_array($activePanel, ['summary','confirm']))
            @include('orders._summary')
            @if($activePanel === 'summary')<div class="space-y-3">@foreach($order->shops as $orderShop)<article class="space-y-3 rounded-lg border p-3"><h3 class="font-semibold">{{ $orderShop->shop_name_snapshot }}</h3>@include('orders._shop-items-summary')@include('orders._shop-facts')</article>@endforeach</div>@endif
        @elseif($activePanel === 'captain')
            <livewire:orders.captain-assignments :order="$order" :key="'workflow-captains-'.$order->id" />
            @if(!$canFinalizePricing && !$order->currentDeliveryPricing && $workflow['mutable'])<p class="rounded-lg bg-amber-50 p-3 text-sm">{{ __('orders.workflow.pricing_requires') }}</p>@endif
            @include('orders._delivery-pricing')
        @elseif($activePanel === 'shops')
            @if($workflow['mutable'])@can('orders.update')<details class="rounded-lg border p-3" @if($order->shops->isEmpty()) open @endif><summary class="cursor-pointer font-semibold">{{ __('orders.ui_add_another_shop') }}</summary><div class="mt-3"><livewire:orders.add-shop :order="$order" /></div></details>@endcan @endif
            @foreach($order->shops as $orderShop)@include('livewire.orders.workflow-shop')@endforeach
        @elseif($activePanel === 'delivery')
            <p>{{ __('orders.ui_operational_status_label') }}: <strong>{{ \App\Support\UiLabels::label('orders.statuses', $order->operational_status) }}</strong></p>
            @foreach($order->shops as $orderShop)<div class="rounded-lg border p-3"><h3 class="font-semibold">{{ $orderShop->shop_name_snapshot }}</h3>@include('orders._shop-facts')</div>@endforeach
        @elseif($activePanel === 'invoice')
            @if($workflow['delivered'] && !$workflow['complete'])<p class="rounded-lg bg-amber-50 p-3">{{ __('orders.workflow.invoice_pending') }}</p>@endif
            @include('livewire.orders.workflow-invoice')
        @elseif($activePanel === 'complete')
            @if($workflow['complete'])
                <p role="status" class="rounded-xl bg-green-50 p-4 font-bold text-green-900">{{ __('orders.workflow.complete') }}</p>
                @include('orders._summary')
                @can('invoices.view')
                    @include('orders._invoices', ['summaryOnly' => true])
                @endcan
                <livewire:orders.captain-assignments :order="$order" />
            @else
                <p>{{ __('orders.workflow.not_complete') }}</p>
            @endif
        @elseif($activePanel === 'exception')
            @include('orders._phase9c2', ['summaryOnly' => true])
            @include('orders._phase85', ['summaryOnly' => true, 'hideCancellationActions' => true])
            <a class="underline" href="{{ route('orders.show', $order) }}">{{ __('orders.workflow.advanced') }}</a>
        @endif
        @if(!$workflow['exceptional'] && !$workflow['complete'] && $workflow['transitionProblem'])<p class="rounded-lg bg-amber-50 p-3 text-sm">{{ $workflow['transitionProblem'] }}</p>@endif
    </x-orders.section>
    <div class="space-y-3" data-workflow-services><h2 class="text-sm font-semibold text-gray-600">{{ __('order_design.operations') }}</h2>
    @can('bill_payments.view')<details class="rounded-xl border border-stone-200 bg-white p-4" data-workflow-bill-payments id="workflow-bill-payments"><summary class="cursor-pointer font-semibold">{{ __('orders.ui_external_bill_payments') }}</summary><div class="mt-3">@include('orders._bill-payments')</div></details>@endcan
    @include('livewire.orders.workflow-cancellation')
    </div>
    @if(!$workflow['exceptional'])<details class="rounded-xl border bg-white p-4"><summary class="cursor-pointer font-semibold text-red-700">{{ __('orders.workflow.advanced') }}</summary><div class="mt-3 space-y-4">@include('orders._phase9c2', ['summaryOnly' => true, 'exceptionsOnly' => true, 'hideCancellationActions' => true])@include('orders._phase85', ['summaryOnly' => true, 'hideCancellationActions' => true])<a href="{{ route('orders.show', $order) }}" class="underline">{{ __('orders.workflow.summary') }}</a></div></details>@endif
</div>
