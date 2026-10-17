@php($specialSources = app(\App\Services\SpecialProductDeliveryService::class)->orderSources($order))
@if($specialSources && !app(\App\Services\OrderMutationGuard::class)->isPricingFrozen($order))
    @can('delivery_pricing.apply')
        <details class="rounded-lg border border-gray-200 p-3"><summary class="cursor-pointer font-medium">{{ __('special.portions') }}</summary>
            <form method="POST" action="{{ route('orders.special-delivery-portions', $order) }}" class="mt-3 space-y-4">@csrf
                <p class="text-sm text-gray-600">{{ __('special.portion_help') }}</p>
                @foreach($specialSources as $source)<fieldset class="grid gap-3 rounded-lg bg-stone-50 p-3 sm:grid-cols-2"><legend>{{ $source['label'] }}</legend>
                    @foreach($order->captainAssignments()->whereNull('removed_at')->with('captain.user')->get() as $assignment)<label>{{ $assignment->captain->user->name }} — {{ __('special.units') }}<input type="text" inputmode="numeric" dir="ltr" name="portions[{{ $source['item_id'] }}][{{ $assignment->captain_id }}]" value="{{ old('portions.'.$source['item_id'].'.'.$assignment->captain_id, $source['assignments'][$assignment->captain_id] ?? '') }}" class="mt-1 w-full rounded-lg border-gray-300" required></label>@endforeach
                </fieldset>@endforeach
                <x-orders.action>{{ __('special.save_portions') }}</x-orders.action>
            </form>
        </details>
    @endcan
@endif
