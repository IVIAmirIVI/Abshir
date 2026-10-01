<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('captains.ui_permanent_transfer') }}</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ route('captains.transfer.store', $captain) }}">@csrf
        <div class="rounded-lg bg-amber-50 p-4 text-amber-900">{{ __('captains.ui_transfer_help', ['captain' => $captain->user->name, 'branch' => $captain->homeBranch->name]) }}</div>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div><x-input-label for="branch_id" :value="__('captains.ui_new_home_branch')" /><select id="branch_id" name="branch_id" class="min-w-0 max-w-full mt-1 block w-full rounded-md border-gray-300" required><option value="">{{ __('captains.ui_select_a_different_branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div><x-input-label for="starts_at" :value="__('captains.ui_effective_timestamp')" /><x-text-input id="starts_at" name="starts_at" type="datetime-local" class="mt-1 block w-full" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required dir="ltr" /></div>
        <div><x-input-label for="reason" :value="__('captains.ui_required_reason')" /><textarea id="reason" name="reason" rows="4" class="mt-1 block w-full rounded-md border-gray-300" required dir="auto">{{ old('reason') }}</textarea></div>
        <div class="flex flex-wrap gap-4"><x-primary-button class="w-full justify-center sm:w-auto">{{ __('captains.ui_record_permanent_transfer') }}</x-primary-button><a class="self-center underline text-sm" href="{{ route('captains.show', $captain) }}">{{ __('common.actions.cancel') }}</a></div>
    </form></div>
</x-app-layout>
