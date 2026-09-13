<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Assign Captain Group</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"><form class="max-w-3xl bg-white p-5 sm:p-8 shadow rounded-lg space-y-6" method="POST" action="{{ route('captains.groups.store', $captain) }}">@csrf
        <p>Assign a historical group membership to <strong>{{ $captain->user->name }}</strong>. Groups describe operations and never store vehicle details.</p>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div><x-input-label for="captain_group_id" value="Group" /><select id="captain_group_id" name="captain_group_id" class="mt-1 block w-full rounded-md border-gray-300" required><option value="">Select an active group</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected((string) old('captain_group_id') === (string) $group->id)>{{ $group->name }}</option>@endforeach</select></div>
        <div class="grid gap-5 sm:grid-cols-2"><div><x-input-label for="joined_at" value="Joined at" /><x-text-input id="joined_at" name="joined_at" type="datetime-local" class="mt-1 block w-full" value="{{ old('joined_at', now()->format('Y-m-d\TH:i')) }}" required /></div><div><x-input-label for="left_at" value="Left at (historical, optional)" /><x-text-input id="left_at" name="left_at" type="datetime-local" class="mt-1 block w-full" value="{{ old('left_at') }}" /></div></div>
        <div class="flex flex-wrap gap-4"><x-primary-button>Assign group</x-primary-button><a class="self-center underline text-sm" href="{{ route('captains.show', $captain) }}">Cancel</a></div>
    </form></div>
</x-app-layout>
