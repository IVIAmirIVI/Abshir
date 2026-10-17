<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('internal.compose') }}</h2></x-slot>
    <x-management.page>
        <p class="alert">{{ __('internal.scope_help') }}</p>
        @if($errors->any())<ul role="alert" class="alert alert-error">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <form method="POST" action="{{ route('notifications.store') }}" class="space-y-4 rounded-xl bg-base-100 p-5 shadow">
            @csrf
            <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <label class="block">{{ __('internal.title') }}<input class="input input-bordered w-full" name="title" value="{{ old('title') }}" maxlength="160" required></label>
            <label class="block">{{ __('internal.body') }}<textarea class="textarea textarea-bordered w-full" name="body" maxlength="2000" required>{{ old('body') }}</textarea></label>
            <label class="block">{{ __('internal.recipients') }}<select class="select select-bordered w-full h-40" name="recipient_ids[]" multiple required>
                @foreach($recipients as $recipient)<option value="{{ $recipient->id }}" @selected(in_array($recipient->id, old('recipient_ids', [])))>{{ $recipient->name }} (#{{ $recipient->id }})</option>@endforeach
            </select></label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label>{{ __('common.terms.branch') }}<select class="select select-bordered w-full" name="branch_id"><option value="">{{ __('internal.all') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                <label>{{ __('internal.order_link') }}<input class="input input-bordered w-full" type="text" inputmode="numeric" dir="ltr" name="order_id" value="{{ old('order_id') }}"></label>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('internal.send') }}</button>
        </form>
    </x-management.page>
</x-app-layout>
