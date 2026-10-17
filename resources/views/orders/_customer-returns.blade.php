@can('order_shop_returns.view')
<details class="min-w-0 rounded-xl border border-stone-200 bg-white p-4" data-customer-returns>
    <summary class="cursor-pointer font-semibold">{{ __('returns.title') }}</summary>
    <div class="mt-4 min-w-0 space-y-4">
    <p class="text-sm leading-6 text-gray-600">{{ __('returns.help') }}</p>
    @if(!\Illuminate\Support\Facades\Schema::hasTable('customer_return_cases') || !\Illuminate\Support\Facades\Schema::hasTable('customer_credit_entries'))
        <p role="status">{{ __('returns.activation') }}</p>
    @else
        @php($returnCases = app(\App\Services\CustomerReturnService::class)->summary(auth()->user(), $order))
        @php($returnInvoice = $order->currentInvoice?->loadMissing('items', 'collection', 'currency'))
        @php($returnParticipants = $order->captainAssignments()->with('captain.user')->orderByDesc('id')->get()->unique('captain_id'))
        @if(!($returnReadOnly ?? false))
        @if(auth()->user()->can('order_shop_returns.create') && auth()->user()->can('invoices.view'))
        @if($returnInvoice?->collection && $order->operational_status === \App\Enums\OrderOperationalStatus::Delivered && !\Illuminate\Support\Facades\Schema::hasColumn('customer_return_cases', 'compensation_contract_version') && \Illuminate\Support\Facades\Schema::hasTable('customer_credit_settlements') && \Illuminate\Support\Facades\DB::table('customer_credit_settlements')->where('order_id', $order->id)->exists())
            <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm">{{ __('credit.refund_blocked') }}</p>
        @elseif($returnInvoice?->collection && $order->operational_status === \App\Enums\OrderOperationalStatus::Delivered)
        <details class="rounded-lg border p-3"><summary class="cursor-pointer font-semibold">{{ __('returns.create') }}</summary>
        <p class="mt-3 text-sm">{{ __('returns.independent_compensation') }}</p>
        <form method="POST" action="{{ route('orders.customer-returns.store', $order) }}" class="mt-4 min-w-0 space-y-4">@csrf
            <input type="hidden" name="request_key" value="{{ old('request_key', (string) str()->uuid()) }}"><input type="hidden" name="invoice_id" value="{{ $returnInvoice->id }}"><input type="hidden" name="currency_id" value="{{ $returnInvoice->currency_id }}">
            <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                <label>{{ __('credit.method') }}<select name="compensation_method" class="mt-1 w-full">
                    @foreach(['CASH','TRANSFER'] as $method)<option value="{{ $method }}" @selected(old('compensation_method') === $method)>{{ __('returns.'.$method) }}</option>@endforeach
                    @can('customer_credits.grant')<option value="CREDIT" @selected(old('compensation_method') === 'CREDIT')>{{ __('credit.CREDIT') }}</option>@endcan
                </select></label>
                <label>{{ __('returns.reason') }}<select name="reason" class="mt-1 w-full">@foreach(['QUALITY','DAMAGE','WRONG_ITEM','OTHER'] as $reason)<option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ __('returns.reasons.'.$reason) }}</option>@endforeach</select></label>
                <label>{{ __('returns.time') }}<x-text-input name="occurred_at" type="text" data-management-datetime dir="ltr" placeholder="YYYY-MM-DDTHH:mm:ss" :value="old('occurred_at', now()->format('Y-m-d\TH:i:s'))" class="mt-1 w-full" /></label>
                <label class="sm:col-span-2">{{ __('returns.notes') }}<textarea name="notes" required maxlength="5000" class="mt-1 w-full">{{ old('notes') }}</textarea></label>
            </div>
            @foreach($returnInvoice->items as $index => $line)
            <fieldset class="grid min-w-0 gap-3 rounded-lg border p-3 sm:grid-cols-2"><legend class="break-words">{{ $line->description }} · <x-decimal :value="$line->quantity" /> {{ $line->unit_name_snapshot }} · <x-decimal :value="$line->line_total" /> {{ $returnInvoice->currency->code }}</legend>
                <input type="hidden" name="lines[{{ $index }}][kind]" value="PRODUCT"><input type="hidden" name="lines[{{ $index }}][invoice_item_id]" value="{{ $line->id }}">
                <label>{{ __('returns.quantity') }}<input name="lines[{{ $index }}][quantity]" value="{{ old('lines.'.$index.'.quantity') }}" type="text" inputmode="decimal" dir="ltr" class="mt-1 w-full"></label>
                <label>{{ __('returns.amount') }}<input name="lines[{{ $index }}][amount]" value="{{ old('lines.'.$index.'.amount') }}" type="text" inputmode="decimal" dir="ltr" class="mt-1 w-full"></label>
            </fieldset>
            @endforeach
            @php($extraIndex = $returnInvoice->items->count())
            <label class="block">{{ __('returns.DELIVERY') }} · <x-decimal :value="$returnInvoice->delivery_total" /> {{ $returnInvoice->currency->code }}<input type="hidden" name="lines[{{ $extraIndex }}][kind]" value="DELIVERY"><input type="hidden" name="lines[{{ $extraIndex }}][quantity]" value="0"><input type="text" name="lines[{{ $extraIndex }}][amount]" value="{{ old('lines.'.$extraIndex.'.amount') }}" inputmode="decimal" dir="ltr" class="mt-1 w-full"></label>
            @foreach($order->billPayments()->where('currency_id', $returnInvoice->currency_id)->orderBy('id')->get() as $bill)
                @php($extraIndex++)
                <label class="block">{{ __('returns.BILL_SERVICE') }} #{{ $bill->id }} · <x-decimal :value="$bill->service_fee" /><input type="hidden" name="lines[{{ $extraIndex }}][kind]" value="BILL_SERVICE"><input type="hidden" name="lines[{{ $extraIndex }}][bill_payment_id]" value="{{ $bill->id }}"><input type="hidden" name="lines[{{ $extraIndex }}][quantity]" value="0"><input type="text" name="lines[{{ $extraIndex }}][amount]" value="{{ old('lines.'.$extraIndex.'.amount') }}" inputmode="decimal" dir="ltr" class="mt-1 w-full"></label>
            @endforeach
            <h3 class="font-semibold">{{ __('returns.responsibilities') }}</h3>
            @php($returnParties = [['party' => 'COMPANY', 'name' => __('returns.COMPANY'), 'field' => null, 'source' => null]])
            @foreach($returnParticipants as $participant)@php($returnParties[] = ['party' => 'CAPTAIN', 'name' => __('returns.CAPTAIN').' · '.$participant->captain->user->name, 'field' => 'order_captain_id', 'source' => $participant->id])@endforeach
            @foreach($order->shops as $shop)@php($returnParties[] = ['party' => 'SHOP', 'name' => __('returns.SHOP').' · '.$shop->shop_name_snapshot, 'field' => 'order_shop_id', 'source' => $shop->id])@endforeach
            <div class="grid min-w-0 gap-3 sm:grid-cols-2">@foreach($returnParties as $index => $party)<label>{{ $party['name'] }}<input type="hidden" name="responsibilities[{{ $index }}][party]" value="{{ $party['party'] }}">@if($party['field'])<input type="hidden" name="responsibilities[{{ $index }}][{{ $party['field'] }}]" value="{{ $party['source'] }}">@endif<input name="responsibilities[{{ $index }}][amount]" value="{{ old('responsibilities.'.$index.'.amount') }}" type="text" inputmode="decimal" dir="ltr" class="mt-1 w-full"></label>@endforeach</div>
            <x-orders.action>{{ __('returns.create') }}</x-orders.action>
        </form></details>
        @else<p class="text-sm text-gray-600">{{ __('returns.no_collection') }}</p>@endif
        @endif
        @endif
        <p class="text-xs text-gray-500">{{ __('returns.recent') }}</p>
        @forelse($returnCases as $case)
        <article class="min-w-0 space-y-3 rounded-lg border p-3">
            <h3 class="font-semibold">#{{ $case->id }} · {{ __('returns.reasons.'.$case->reason) }} · <x-decimal :value="$case->amount" /> {{ \App\Models\Currency::find($case->currency_id)?->code }}</h3>
            <p class="text-sm">{{ __('returns.products_'.$case->product_scope) }} · {{ __('returns.invoice_'.$case->invoice_refund_scope) }}</p>
            <p class="break-words">{{ $case->notes }}</p><p>{{ __('returns.remaining') }}: <x-decimal :value="$case->unpaid_compensation" /></p>
            <p class="text-sm">{{ __('credit.method') }}: {{ $case->compensation_method === 'CREDIT' ? __('credit.CREDIT') : __('returns.'.$case->compensation_method) }}</p>
            @if($case->compensation_method === 'CREDIT')<p role="status" class="rounded-lg bg-green-50 p-3 text-sm">{{ __('credit.credit_compensation') }} <x-decimal :value="$case->credit_granted" /> · {{ __('credit.redeemed') }}: <x-decimal :value="$case->credit_used" /></p>@endif
            <details class="rounded-lg border p-3"><summary class="cursor-pointer text-sm">{{ __('returns.details') }}</summary>
                <p class="mt-2 text-sm">{{ \App\Support\UiDate::datetime($case->occurred_at) }} · {{ __('returns.employee') }} #{{ $case->recorded_by }}</p>
                @foreach($case->lines as $line)
                    <p class="mt-2 break-words text-sm">{{ __('returns.'.$line->kind) }}
                        @if($line->kind === 'PRODUCT') · {{ $line->description }} · <x-decimal :value="$line->quantity" /> {{ $line->unit_name_snapshot }}
                        @elseif($line->kind === 'BILL_SERVICE') #{{ $line->bill_payment_id }} @endif
                        · <x-decimal :value="$line->amount" />
                    </p>
                @endforeach
            </details>
            @foreach($case->responsibilities as $party)
                <p class="text-sm">{{ __('returns.'.$party->party) }}
                    @if($party->order_captain_id) · {{ $returnParticipants->firstWhere('id', $party->order_captain_id)?->captain->user->name ?? '#'.$party->order_captain_id }}
                    @elseif($party->order_shop_id) · {{ $order->shops->firstWhere('id', $party->order_shop_id)?->shop_name_snapshot ?? '#'.$party->order_shop_id }} @endif
                    · <x-decimal :value="$party->amount" />
                    @if($party->party === 'CAPTAIN')
                        · {{ __('returns.funded') }}: <x-decimal :value="$party->funded_amount" />
                        · {{ __('returns.unfunded') }}: <x-decimal :value="$party->unfunded_amount" />
                        · {{ __('returns.repaid') }}: <x-decimal :value="$party->repaid_amount" />
                        · {{ __('returns.outstanding') }}: <x-decimal :value="$party->outstanding" />
                    @elseif($party->party === 'SHOP')
                        · {{ __('returns.repaid') }}: <x-decimal :value="$party->repaid_amount" />
                        · {{ __('returns.shop_outstanding') }}: <x-decimal :value="$party->outstanding" />
                    @endif
                </p>
            @endforeach
            @foreach($case->movements as $movement)<p class="break-words text-sm">{{ __('returns.'.$movement->kind) }} · <x-decimal :value="$movement->amount" /> · {{ __('returns.'.$movement->method) }} · {{ \App\Support\UiDate::datetime($movement->occurred_at) }} · {{ $movement->reference }} · <a class="underline" href="{{ route('orders.customer-returns.evidence', [$order, $movement]) }}">{{ __('returns.evidence') }}</a></p>@endforeach
            @if(!($returnReadOnly ?? false) && ($case->compensation_method !== 'CREDIT' || $case->responsibilities->contains(fn ($party) => in_array($party->party, ['SHOP','CAPTAIN'], true) && \Brick\Math\BigDecimal::of($party->outstanding)->isGreaterThan(0)))) @if(auth()->user()->can('order_shop_returns.create') && auth()->user()->can('invoices.view'))
            @if($case->responsibilities->count() > 1 && \Brick\Math\BigDecimal::of($case->unpaid_compensation)->isGreaterThan(0))
                <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm">{{ __('returns.ambiguous_funding') }}</p>
            @endif
            <details><summary class="cursor-pointer font-semibold">{{ __('returns.movement') }}</summary>
            <form method="POST" enctype="multipart/form-data" action="{{ route('orders.customer-returns.movements', [$order, $case]) }}" class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2">@csrf
                <input type="hidden" name="request_key" value="{{ (string) str()->uuid() }}"><input type="hidden" name="currency_id" value="{{ $case->currency_id }}">
                <label>{{ __('returns.kind') }}<select name="kind" class="mt-1 w-full"><option value="COMPENSATION" @disabled($case->compensation_method === 'CREDIT')>{{ __('returns.COMPENSATION') }}</option><option value="RECOVERY">{{ __('returns.RECOVERY') }}</option></select></label>
                <label>{{ __('returns.responsibilities') }}<select name="responsibility_id" class="mt-1 w-full"><option value="">{{ __('returns.COMPENSATION') }}</option>@foreach($case->responsibilities->where('party', '!=', 'COMPANY') as $party)<option value="{{ $party->id }}" @disabled(\Brick\Math\BigDecimal::of($party->outstanding)->isZero())>#{{ $party->id }} · {{ __('returns.'.$party->party) }} · {{ $party->order_captain_id ? ($returnParticipants->firstWhere('id', $party->order_captain_id)?->captain->user->name ?? '#'.$party->order_captain_id) : ($order->shops->firstWhere('id', $party->order_shop_id)?->shop_name_snapshot ?? '#'.$party->order_shop_id) }} · {{ __('returns.outstanding') }}: {{ $party->outstanding }}</option>@endforeach</select></label>
                <label>{{ __('returns.amount') }}<input required type="text" inputmode="decimal" name="amount" dir="ltr" class="mt-1 w-full"></label>
                <label>{{ __('returns.method') }}<select name="method" class="mt-1 w-full">@foreach(['CASH','TRANSFER'] as $method)<option value="{{ $method }}">{{ __('returns.'.$method) }}</option>@endforeach</select></label>
                <label>{{ __('returns.funding') }}<input required name="funding_source" maxlength="255" class="mt-1 w-full"></label>
                <label>{{ __('returns.reference') }}<input required name="reference" maxlength="191" class="mt-1 w-full"></label>
                <label>{{ __('returns.time') }}<x-text-input name="occurred_at" type="text" data-management-datetime dir="ltr" placeholder="YYYY-MM-DDTHH:mm:ss" :value="now()->format('Y-m-d\TH:i:s')" class="mt-1 w-full" /></label>
                <label>{{ __('returns.evidence') }}<input required type="file" name="evidence" accept="image/png,image/jpeg,image/webp" class="mt-1 w-full"></label>
                <label class="flex items-start gap-2 text-sm sm:col-span-2"><input required type="checkbox" name="actual_confirmed" value="1" class="mt-1"><span>{{ __('returns.attest') }}</span></label>
                <x-orders.action>{{ __('returns.movement') }}</x-orders.action>
            </form></details>
            @endif @endif
        </article>
        @empty<p>{{ __('returns.empty') }}</p>@endforelse
    @endif
    </div>
</details>
@endcan
