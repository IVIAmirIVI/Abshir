@php($editing = $proposal !== null)
@php($defaultKind = $editing ? $proposal->kind : (in_array(old('kind'), $creationKinds, true) ? old('kind') : $creationKinds[0]))
<form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('captains.requests.revise', [$captain, $proposal]) : route('captains.requests.store', $captain) }}" class="captain-request-form min-w-0 w-full space-y-4" x-data="{kind: @js($defaultKind)}">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
    @if($editing)
        <input type="hidden" name="expected_version" value="{{ $proposal->version }}">
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.request_type') }}</span><select name="kind" x-model="kind" class="mt-1 block w-full rounded-lg" required>
                @foreach($creationKinds as $kind)<option value="{{ $kind }}" @selected($defaultKind === $kind)>{{ __('captain_requests.kinds.'.$kind) }}</option>@endforeach
            </select></label>
            <label class="block min-w-0 space-y-1"><span>{{ __('common.terms.branch') }}</span><select name="branch_id" class="mt-1 block w-full rounded-lg" required>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach
            </select></label>
            <label class="block min-w-0 space-y-1"><span>{{ __('common.terms.currency') }}</span><select name="currency_id" class="mt-1 block w-full rounded-lg" required>
                @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) old('currency_id') === (string) $currency->id)>{{ $currency->code }}</option>@endforeach
            </select></label>
            <label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.related_order') }}</span><input name="order_id" value="{{ old('order_id') }}" type="text" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg"></label>
        </div>
    @endif
    <label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.amount') }}</span><input name="amount" value="{{ old('amount', $proposal?->amount) }}" type="text" inputmode="decimal" dir="ltr" required class="mt-1 block w-full rounded-lg"></label>
    <label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.description') }}</span><textarea name="description" maxlength="5000" required class="mt-1 block w-full rounded-lg">{{ old('description', $proposal?->description) }}</textarea></label>
    <div class="grid gap-4 sm:grid-cols-2" x-show="kind === 'EXPENSE'">
        <label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.category') }}</span><select name="category" class="mt-1 block w-full rounded-lg" :disabled="kind !== 'EXPENSE'" :required="kind === 'EXPENSE'">
            @foreach(['TRANSPORT','COMMUNICATION','EQUIPMENT','OTHER'] as $category)<option value="{{ $category }}" @selected(old('category', $proposal?->category) === $category)>{{ __('captain_requests.categories.'.$category) }}</option>@endforeach
        </select></label>
        <label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.expense_date') }}</span><input name="expense_date" type="date" dir="ltr" value="{{ old('expense_date', $proposal?->expense_date?->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg" :disabled="kind !== 'EXPENSE'" :required="kind === 'EXPENSE'"></label>
    </div>
    <label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.evidence') }}</span><input name="evidence" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full"></label>
    @if($editing)<label class="block min-w-0 space-y-1"><span>{{ __('captain_requests.revision_note') }}</span><textarea name="revision_note" required maxlength="2000" class="mt-1 block w-full rounded-lg">{{ old('revision_note') }}</textarea></label>@endif
    @if($editing)
        <x-primary-button>{{ __('captain_requests.revise') }}</x-primary-button>
    @else
        <x-primary-button><span x-text="kind === 'BONUS' ? @js(__('captain_requests.propose_bonus')) : @js(__('captain_requests.propose_expense'))">{{ __($defaultKind === 'EXPENSE' ? 'captain_requests.propose_expense' : 'captain_requests.propose_bonus') }}</span></x-primary-button>
    @endif
</form>
