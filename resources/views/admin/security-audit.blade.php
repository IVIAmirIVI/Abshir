<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('internal.audit_title') }}</h2></x-slot>
    <x-management.page>
        @if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        @if(!$available)<p class="alert" role="status">{{ __('internal.activation_required') }}</p>
        @else
            <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label>{{ __('internal.actor') }}<input class="input input-bordered w-full" name="actor_id" inputmode="numeric" dir="ltr" value="{{ $filters['actor_id'] ?? '' }}"></label>
                <label>{{ __('internal.resource') }}<select class="select select-bordered w-full" name="resource_kind"><option value="">{{ __('internal.all') }}</option>@foreach(['USER','ROLE','BRANCH','NOTICE'] as $kind)<option value="{{ $kind }}" @selected(($filters['resource_kind'] ?? '') === $kind)>{{ __('internal.resources.'.$kind) }}</option>@endforeach</select></label>
                <label>{{ __('internal.resource_key') }}<input class="input input-bordered w-full" name="resource_key" dir="ltr" value="{{ $filters['resource_key'] ?? '' }}"></label>
                <label>{{ __('internal.action') }}<select class="select select-bordered w-full" name="action"><option value="">{{ __('internal.all') }}</option>@foreach(array_keys(__('internal.actions')) as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ __('internal.actions.'.$action) }}</option>@endforeach</select></label>
                <label>{{ __('common.terms.branch') }}<select class="select select-bordered w-full" name="branch_id"><option value="">{{ __('internal.all') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                <button class="btn btn-outline self-end">{{ __('internal.filter') }}</button>
            </form>
            <div class="space-y-4">
                @forelse($audits as $audit)
                    <x-management.record>
                        <h3 class="font-semibold">{{ __('internal.actions.'.$audit->action) }}</h3>
                        <p>{{ __('internal.actor') }}: <x-ltr>{{ $audit->actor_id }}</x-ltr> · {{ __('internal.resources.'.$audit->resource_kind) }} #<x-ltr>{{ $audit->resource_key }}</x-ltr> · {{ $audit->branch?->name ?? __('internal.all') }}</p>
                        <p>{{ __('internal.time') }}: <x-ltr>{{ \App\Support\UiDate::datetime($audit->recorded_at) }}</x-ltr></p>
                        <div class="grid gap-3 sm:grid-cols-2"><p>{{ __('internal.before') }}: <x-ltr class="break-all">{{ json_encode($audit->before_values, JSON_UNESCAPED_UNICODE) }}</x-ltr></p><p>{{ __('internal.after') }}: <x-ltr class="break-all">{{ json_encode($audit->after_values, JSON_UNESCAPED_UNICODE) }}</x-ltr></p></div>
                    </x-management.record>
                @empty<p role="status">{{ __('internal.audit_empty') }}</p>@endforelse
            </div>
            {{ $audits->links() }}
        @endif
    </x-management.page>
</x-app-layout>
