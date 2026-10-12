@php($editing = $proposal !== null)
<form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('captains.requests.revise', [$captain, $proposal]) : route('captains.requests.store', $captain) }}" class="space-y-4" x-data="{kind: @js($editing ? $proposal->kind : old('kind', 'BONUS'))}">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
    @if($editing)
        <input type="hidden" name="expected_version" value="{{ $proposal->version }}">
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1"><span>{{ __('captain_requests.title') }}</span><select name="kind" x-model="kind" class="select select-bordered w-full" required>
                @foreach(['BONUS','EXPENSE'] as $kind)<option value="{{ $kind }}" @selected(old('kind','BONUS') === $kind)>{{ __('captain_requests.kinds.'.$kind) }}</option>@endforeach
            </select></label>
            <label class="space-y-1"><span>{{ __('common.terms.branch') }}</span><select name="branch_id" class="select select-bordered w-full" required>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach
            </select></label>
            <label class="space-y-1"><span>{{ __('common.terms.currency') }}</span><select name="currency_id" class="select select-bordered w-full" required>
                @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string) old('currency_id') === (string) $currency->id)>{{ $currency->code }}</option>@endforeach
            </select></label>
            <label class="space-y-1"><span>{{ __('captain_requests.related_order') }}</span><input name="order_id" value="{{ old('order_id') }}" type="text" inputmode="numeric" dir="ltr" class="input input-bordered w-full"></label>
        </div>
    @endif
    <label class="block space-y-1"><span>{{ __('captain_requests.amount') }}</span><input name="amount" value="{{ old('amount', $proposal?->amount) }}" type="text" inputmode="decimal" dir="ltr" required class="input input-bordered w-full"></label>
    <label class="block space-y-1"><span>{{ __('captain_requests.description') }}</span><textarea name="description" maxlength="5000" required class="textarea textarea-bordered w-full">{{ old('description', $proposal?->description) }}</textarea></label>
    <div class="grid gap-4 sm:grid-cols-2" x-show="kind === 'EXPENSE'">
        <label class="space-y-1"><span>{{ __('captain_requests.category') }}</span><select name="category" class="select select-bordered w-full" :disabled="kind !== 'EXPENSE'" :required="kind === 'EXPENSE'">
            @foreach(['TRANSPORT','COMMUNICATION','EQUIPMENT','OTHER'] as $category)<option value="{{ $category }}" @selected(old('category', $proposal?->category) === $category)>{{ __('captain_requests.categories.'.$category) }}</option>@endforeach
        </select></label>
        <label class="space-y-1"><span>{{ __('captain_requests.expense_date') }}</span><input name="expense_date" type="date" dir="ltr" value="{{ old('expense_date', $proposal?->expense_date?->format('Y-m-d')) }}" class="input input-bordered w-full" :disabled="kind !== 'EXPENSE'" :required="kind === 'EXPENSE'"></label>
    </div>
    <label class="block space-y-1"><span>{{ __('captain_requests.evidence') }}</span><input name="evidence" type="file" accept="image/jpeg,image/png,image/webp" class="file-input file-input-bordered w-full"></label>
    @if($editing)<label class="block space-y-1"><span>{{ __('captain_requests.revision_note') }}</span><textarea name="revision_note" required maxlength="2000" class="textarea textarea-bordered w-full">{{ old('revision_note') }}</textarea></label>@endif
    <button class="btn btn-primary" type="submit">{{ __($editing ? 'captain_requests.revise' : 'captain_requests.save') }}</button>
</form>
