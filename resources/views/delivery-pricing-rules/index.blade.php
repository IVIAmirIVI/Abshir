<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Delivery pricing rules</h2></x-slot>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6">
        @if(session('status'))<p class="rounded bg-green-50 p-4">{{ session('status') }}</p>@endif
        <div class="flex flex-wrap justify-between gap-3"><p>Directional, exact Area-pair, Currency, and Order Pricing Class rules with retained history.</p>@can('delivery_pricing_rules.create')<a class="rounded bg-gray-800 px-4 py-2 text-white" href="{{ route('delivery-pricing-rules.create') }}">Create rule</a>@endcan</div>
        <form class="grid gap-3 rounded bg-white p-4 shadow sm:grid-cols-2 lg:grid-cols-6">
            <select name="branch_id" class="rounded border-gray-300"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
            <select name="from_area_id" class="rounded border-gray-300"><option value="">All origins</option>@foreach($areas as $a)<option value="{{ $a->id }}" @selected(request('from_area_id') == $a->id)>{{ $a->path() }}</option>@endforeach</select>
            <select name="to_area_id" class="rounded border-gray-300"><option value="">All destinations</option>@foreach($areas as $a)<option value="{{ $a->id }}" @selected(request('to_area_id') == $a->id)>{{ $a->path() }}</option>@endforeach</select>
            <select name="currency_id" class="rounded border-gray-300"><option value="">All currencies</option>@foreach($currencies as $c)<option value="{{ $c->id }}" @selected(request('currency_id') == $c->id)>{{ $c->code }}</option>@endforeach</select>
            <select name="order_pricing_class" class="rounded border-gray-300"><option value="">All pricing classes</option>@foreach($pricingClasses as $class)<option value="{{ $class->value }}" @selected(request('order_pricing_class') === $class->value)>{{ $class->label() }}</option>@endforeach</select>
            <select name="scope" class="rounded border-gray-300"><option value="">All history</option><option value="current" @selected(request('scope') === 'current')>Current</option></select>
            <x-primary-button>Filter</x-primary-button>
        </form>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach($rules as $rule)
                <article class="rounded bg-white p-5 shadow">
                    <div class="flex flex-wrap justify-between gap-2"><strong>{{ $rule->fromArea->path() }} → {{ $rule->toArea->path() }}</strong><span>{{ $rule->delivery_unit_fee }} {{ $rule->currency->code }}</span></div>
                    <p class="font-medium">{{ $rule->order_pricing_class?->label() ?? 'Legacy unclassified (historical only)' }}</p>
                    <p class="text-sm">{{ $rule->effective_from->format('Y-m-d H:i') }} → {{ $rule->effective_until?->format('Y-m-d H:i') ?? 'Current' }} · {{ $rule->is_active ? 'Active' : 'Historical/inactive' }}</p>
                    @if($rule->order_pricing_class)<div class="mt-3 flex flex-wrap gap-3">@can('delivery_pricing_rules.update')<a class="underline" href="{{ route('delivery-pricing-rules.edit', $rule) }}">Create new version</a>@endcan @can($rule->is_active ? 'delivery_pricing_rules.deactivate' : 'delivery_pricing_rules.activate')<form method="POST" action="{{ route('delivery-pricing-rules.status', $rule) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $rule->is_active ? 0 : 1 }}"><button class="underline">{{ $rule->is_active ? 'Deactivate' : 'Activate' }}</button></form>@endcan</div>@endif
                </article>
            @endforeach
        </div>
        {{ $rules->links() }}
    </div>
</x-app-layout>
