<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('commissions.title') }} — {{ $shop->name }}</h2></x-slot>
    <x-management.page>
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm" role="status">{{ __('commissions.non_posting') }}</p>
        @if($accountingInstalled)<p class="text-sm">{{ __('commissions.activation_help') }}</p>@endif
        <p class="text-sm">{{ __('commissions.approved_direction') }} {{ __('commissions.precedence') }}</p>
        <p class="text-sm">{{ __('shops.ui_partnership_history') }}: <x-ltr>#{{ $partnership->id }}</x-ltr> · {{ \App\Support\UiLabels::label('shops.partnership_statuses', $partnership->status) }} · <x-ltr>{{ \App\Support\UiDate::datetime($partnership->starts_at) }}</x-ltr></p>
        @if(session('status'))<p class="rounded-lg bg-green-50 p-3" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="rounded-lg bg-red-50 p-3 text-red-800" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(!$installed)
            <p class="rounded-xl border p-4">{{ __('commissions.activation_required') }}</p>
        @else
            @can('shop_partnerships.create')
                <x-orders.section id="partnership-commission-draft-create">
                    <h3 class="font-bold">{{ __('commissions.new_draft') }}</h3>
                    <form method="POST" action="{{ route('shops.partnerships.commissions.store', [$shop, $partnership]) }}" class="grid gap-4 sm:grid-cols-2" x-data="{ target: @js(old('scope', old('product_group_id') ? 'group' : (old('method') && !old('product_id') ? 'shop' : 'product'))), product: @js((string) old('product_id', '')), group: @js((string) old('product_group_id', '')) }">
                        @csrf
                        <label class="block text-sm">{{ __('commissions.target') }}<select name="scope" class="mt-1 w-full" x-model="target" @change="product = ''; group = ''"><option value="product">{{ __('commissions.product') }}</option><option value="group">{{ __('commissions.group') }}</option>@if($agreementsInstalled)<option value="shop">{{ __('commissions.shop') }}</option>@endif</select></label>
                        <label class="block text-sm">{{ __('commissions.currency') }}<select name="currency_id" @required($agreementsInstalled) class="mt-1 w-full"><option value="">{{ __('commissions.unspecified_currency') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) old('currency_id') === (string) $currency->id)>{{ $currency->code }}</option>@endforeach</select></label>
                        <label class="block text-sm sm:col-span-2" x-show="target === 'product'">{{ __('commissions.product') }}<select name="product_id" x-model="product" :disabled="target !== 'product'" :required="target === 'product'" class="mt-1 w-full"><option value="">{{ __('orders.wizard.choose') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></label>
                        <label class="block text-sm sm:col-span-2" x-show="target === 'group'" x-cloak>{{ __('commissions.group') }}<select name="product_group_id" x-model="group" disabled :disabled="target !== 'group'" :required="target === 'group'" class="mt-1 w-full"><option value="">{{ __('orders.wizard.choose') }}</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected((string) old('product_group_id') === (string) $group->id)>{{ $group->name }}</option>@endforeach</select></label>
                        @if($products->isEmpty())<p x-show="target === 'product'" class="text-sm text-amber-800 sm:col-span-2" role="status">{{ __('commissions.no_shop_products') }}</p>@endif
<p class="text-xs text-gray-600 sm:col-span-2">{{ __('commissions.scope_help') }} {{ __('commissions.catalog_help') }}</p>
                        @if($agreementsInstalled) @include('shops.commission-agreement-fields', ['agreement' => null]) @endif
                        <label class="block text-sm sm:col-span-2">{{ __('commissions.terms') }}<textarea name="terms" required maxlength="5000" dir="auto" class="mt-1 w-full rounded-lg border-gray-300">{{ old('terms') }}</textarea></label>
                        <x-primary-button data-commission-action="save-draft">{{ __('commissions.save_draft') }}</x-primary-button>
                    </form>
                </x-orders.section>
            @endcan
            @if($agreementsInstalled && auth()->user()->can('products.view') && auth()->user()->can('currencies.view'))
                <x-orders.section id="commission-matching-preview">
                    <h3 class="font-bold">{{ __('commissions.preview') }}</h3>
                    <form method="GET" class="grid gap-3 sm:grid-cols-2">
                        <label>{{ __('commissions.product') }}<select name="preview_product_id" required class="mt-1 w-full"><option value="">{{ __('orders.wizard.choose') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(($filters['preview_product_id'] ?? null) == $product->id)>{{ $product->name }}</option>@endforeach</select></label>
                        <label>{{ __('commissions.currency') }}<select name="preview_currency_id" required class="mt-1 w-full"><option value="">{{ __('orders.wizard.choose') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(($filters['preview_currency_id'] ?? null) == $currency->id)>{{ $currency->code }}</option>@endforeach</select></label>
                        <label>{{ __('commissions.preview_time') }}<x-text-input type="text" data-management-datetime placeholder="YYYY-MM-DDTHH:mm" autocomplete="off" lang="en" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(:[0-9]{2}([.][0-9]{1,6})?)?(Z|[+\-][0-9]{2}:[0-9]{2})?" name="preview_at" required dir="ltr" value="{{ $filters['preview_at'] ?? now()->setTimezone('Asia/Damascus')->format('Y-m-d\TH:i:s.u') }}" /></label>
                        <x-secondary-button type="submit" data-commission-action="preview-rule">{{ __('commissions.preview') }}</x-secondary-button>
                    </form>
                    @if($preview)
                        <p role="status">{{ __('commissions.'.$preview['reason']) }}</p>
                        @if($preview['rule'])<p>{{ $preview['rule']->target_name_snapshot }} · {{ __('commissions.methods.'.$preview['rule']->method) }} · <x-ltr>{{ $preview['rule']->commission_value }} {{ $preview['rule']->method === 'PERCENTAGE' ? '%' : $preview['rule']->currency_code_snapshot }}</x-ltr> · {{ __('commissions.version') }} <x-ltr>{{ $preview['rule']->version }}</x-ltr></p>@endif
                    @endif
                </x-orders.section>
            @endif
            <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
                <label class="text-sm">{{ __('commissions.search') }}<x-text-input name="search" value="{{ $filters['search'] ?? '' }}" dir="auto" class="mt-1 block" /></label>
                <label class="text-sm">{{ __('commissions.target') }}<select name="target" class="mt-1 block"><option value="">{{ __('captain_dashboard.orders_all') }}</option>@foreach(['shop', 'product', 'group'] as $type)<option value="{{ $type }}" @selected(($filters['target'] ?? '') === $type)>{{ __('commissions.'.$type) }}</option>@endforeach</select></label>
                <label class="flex items-center gap-2"><input type="checkbox" name="history" value="1" @checked($filters['history'] ?? false)>{{ __('commissions.show_history') }}</label>
                <x-secondary-button type="submit" data-commission-action="refresh">{{ __('dashboard.refresh') }}</x-secondary-button>
            </form>
            <div class="grid gap-4 md:grid-cols-2">
                @forelse($drafts as $draft)
                    <x-management.record>
                        <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold break-words">{{ $draft->target_name_snapshot }}</h3><x-orders.indicator>{{ __('commissions.draft_only') }}</x-orders.indicator></div>
                        <p class="text-sm">{{ __('commissions.'.($draft->product_id ? 'product' : ($draft->product_group_id ? 'group' : 'shop'))) }} · {{ __('commissions.version') }} <x-ltr>{{ $draft->version }}</x-ltr> · <x-ltr>{{ $draft->currency_code_snapshot ?? '—' }}</x-ltr> · {{ __('commissions.'.($draft->nextVersion ? 'previous' : 'latest')) }}</p>
                        @if($draft->method)
                            <p class="text-sm">{{ __('commissions.methods.'.$draft->method) }} · <x-ltr>{{ $draft->commission_value }} {{ $draft->method === 'PERCENTAGE' ? '%' : $draft->currency_code_snapshot }}</x-ltr>@if($draft->product_group_id) · {{ __('commissions.priority') }} <x-ltr>{{ $draft->group_priority }}</x-ltr>@endif</p>
                            <p class="text-xs">{{ __('commissions.effective_from') }} <x-ltr>{{ \App\Support\UiDate::datetime($draft->effective_from) }}</x-ltr> · {{ __('commissions.effective_until') }} <x-ltr>{{ $draft->effective_until ? \App\Support\UiDate::datetime($draft->effective_until) : '—' }}</x-ltr></p>
                        @endif
                        <p class="text-xs text-gray-600"><x-ltr>{{ \App\Support\UiDate::datetime($draft->recorded_at, true) }}</x-ltr> · {{ $draft->createdBy->name }}</p>
                        <p class="whitespace-pre-wrap break-words text-sm">{{ $draft->terms }}</p>
                        @if($accountingInstalled && $draft->method)
                            @php($activation = $activations->get($draft->id))
                            <p class="text-sm">{{ $activation?->state === 'ACTIVE' ? __('commissions.active') : __('commissions.draft_only') }}</p>
                            @if($activation)<p class="text-xs"><x-ltr>{{ \App\Support\UiDate::datetime($activation->recorded_at, true) }}</x-ltr> · {{ $activation->actor_name }} · {{ $activation->reason }}</p>@endif
                            @if(auth()->user()->can('shop_partnerships.update') && auth()->user()->can('currencies.view') && (!$draft->nextVersion || $activation?->state === 'ACTIVE'))
                                <form method="POST" action="{{ route('shops.partnerships.commissions.activation', [$shop, $partnership, $draft]) }}" class="space-y-2">
                                    @csrf <input type="hidden" name="is_active" value="{{ $activation?->state === 'ACTIVE' ? '0' : '1' }}">
                                    <label class="block text-sm">{{ __('commissions.activation_reason') }}<x-text-input name="reason" required maxlength="1000" class="block w-full" dir="auto" /></label>
                                    <x-orders.action>{{ $activation?->state === 'ACTIVE' ? __('common.actions.deactivate') : __('common.actions.activate') }}</x-orders.action>
                                </form>
                            @endif
                        @endif
                        @if(!$draft->nextVersion)@can('shop_partnerships.update')
                            <details class="border-t pt-3"><summary class="cursor-pointer text-sm font-semibold">{{ __('commissions.revise') }}</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('shops.partnerships.commissions.revise', [$shop, $partnership, $draft]) }}">@csrf<input type="hidden" name="expected_version" value="{{ $draft->version }}">@if($agreementsInstalled && $draft->currency_id && auth()->user()->can('currencies.view')) @include('shops.commission-agreement-fields', ['agreement' => $draft]) @endif<label class="block text-sm">{{ __('commissions.terms') }}<textarea name="terms" required maxlength="5000" dir="auto" class="mt-1 w-full rounded-lg border-gray-300">{{ $draft->terms }}</textarea></label><x-orders.action>{{ __('commissions.save_revision') }}</x-orders.action></form></details>
                        @endcan @endif
                    </x-management.record>
                @empty<p class="text-sm">{{ __('commissions.empty') }}</p>@endforelse
            </div>
            {{ $drafts->links() }}
        @endif
        <a class="btn btn-ghost" href="{{ route('shops.show', $shop) }}">{{ __('shops.ui_back_to_shops') }}</a>
    </x-management.page>
</x-app-layout>
