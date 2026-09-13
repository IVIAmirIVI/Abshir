<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Temporary Assignment</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ route('captains.assignments.store', $captain) }}">@csrf
        <div class="rounded-lg bg-blue-50 p-4 text-blue-900"><strong>{{ $captain->user->name }}</strong> remains permanently based at {{ $captain->homeBranch->name }}.</div>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div><x-input-label for="branch_id" value="Temporary branch" /><select id="branch_id" name="branch_id" class="mt-1 block w-full rounded-md border-gray-300" required><option value="">Select a different branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="starts_at" value="Starts at" /><x-text-input id="starts_at" name="starts_at" type="datetime-local" class="mt-1 block w-full" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required /></div><div><x-input-label for="ends_at" value="Ends at (optional)" /><x-text-input id="ends_at" name="ends_at" type="datetime-local" class="mt-1 block w-full" value="{{ old('ends_at') }}" /></div></div>
        <div><x-input-label for="reason" value="Reason" /><textarea id="reason" name="reason" rows="4" class="mt-1 block w-full rounded-md border-gray-300">{{ old('reason') }}</textarea></div>
        <div class="flex flex-wrap gap-4"><x-primary-button>Create temporary assignment</x-primary-button><a class="self-center underline text-sm" href="{{ route('captains.show', $captain) }}">Cancel</a></div>
    </form></div>
</x-app-layout>
