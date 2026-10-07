<section class="rounded-xl border border-brand-primary/10 bg-white p-4 shadow-sm sm:p-5 space-y-5">
    <x-input-error :messages="$errors->all()" role="alert" />
    <div>
        <h3 class="text-lg font-bold text-brand-primary">{{ __('orders.ui_assignments') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('orders.ui_captain_eligibility_help') }}</p>
    </div>

    @if(session('order-action-status'))<p class="rounded-lg bg-green-50 p-3 text-sm text-green-900">{{ session('order-action-status') }}</p>@endif

    @if($orderMutable)@can('orders.captains.assign')
        @if($this->eligibleCaptains->isEmpty())
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('orders.ui_no_eligible_captains') }}</p>
        @else
            <form wire:submit="assign" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                <label class="block">
                    <span class="text-sm font-medium">{{ __('orders.ui_captain_label') }}</span>
                    <select wire:model="captainId" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm"><option value="">{{ __('orders.ui_choose_captain') }}</option>@foreach($this->eligibleCaptains as $captain)<option value="{{ $captain->id }}">{{ $captain->user->name }}</option>@endforeach</select>
                    <x-input-error :messages="$errors->get('captainId')" class="mt-1" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">{{ __('orders.ui_assignment_role') }}</span>
                    <select wire:model="assignmentRole" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">@if($order->captainAssignments->whereNull('removed_at')->where('assignment_role', \App\Enums\OrderCaptainRole::PrimaryCollection)->isEmpty())<option value="PRIMARY_COLLECTION">{{ __('orders.ui_primary_collection') }}</option>@else<option value="SUPPORT">{{ __('orders.ui_support') }}</option>@endif</select>
                    <x-input-error :messages="$errors->get('assignmentRole')" class="mt-1" />
                </label>
                <x-primary-button wire:loading.attr="disabled" wire:target="assign" class="justify-center disabled:opacity-60">{{ __('common.actions.assign') }}</x-primary-button>
            </form>
        @endif
    @endcan @endif

    <div class="space-y-3">
        @forelse($order->captainAssignments->sortByDesc('assigned_at') as $assignment)
            <article wire:key="assignment-{{ $assignment->id }}" class="rounded-lg border border-gray-200 p-3">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <p class="font-medium">{{ $assignment->captain->user->name }}</p>
                    <p class="text-sm text-gray-600">{{ \App\Support\UiLabels::label('orders.captain_roles', $assignment->assignment_role) }} · {{ $assignment->removed_at ? __('orders.ui_removed').$assignment->removed_at->format('Y-m-d H:i') : __('common.operational_active') }}</p>
                </div>
                @can('captain_financials.view')<a href="{{ route('captains.financials.show', $assignment->captain) }}" class="mt-2 inline-block text-sm text-brand-primary underline">{{ __('captains.ui_receipts_payouts_and_outstanding_receivables') }}</a>@endcan
                @if($orderMutable && ! $assignment->removed_at)@can('orders.captains.remove')
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <input wire:model="removalReasons.{{ $assignment->id }}" placeholder="{{ __('orders.ui_removal_reason') }}" dir="auto" class="min-w-0 flex-1 rounded-lg border-gray-300">
                        <button type="button" wire:click="remove({{ $assignment->id }})" wire:loading.attr="disabled" wire:confirm="{{ __('orders.ui_confirm_remove_captain') }}" class="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-60">{{ __('common.actions.remove') }}</button>
                    </div>
                    <x-input-error :messages="$errors->get('removalReasons.'.$assignment->id)" class="mt-1" />
                @endcan @endif
            </article>
        @empty
            <p class="text-sm text-gray-600">{{ __('orders.ui_no_assignments') }}</p>
        @endforelse
    </div>

    @can('orders.captains.remove')
        @if($orderMutable)@can('orders.captains.assign')
        @if($order->captainAssignments->whereNull('removed_at')->isNotEmpty() && $this->switchCandidates->isNotEmpty())
            <details class="rounded-lg border border-gray-200 p-3">
                <summary class="cursor-pointer font-medium">{{ __('orders.ui_switch_primary') }}</summary>
                <form wire:submit="switchPrimary" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                    <select wire:model="switchCaptainId" class="block w-full rounded-lg border-gray-300"><option value="">{{ __('orders.ui_choose_captain') }}</option>@foreach($this->switchCandidates as $captain)<option value="{{ $captain->id }}">{{ $captain->user->name }}</option>@endforeach</select>
                    <input wire:model="switchReason" placeholder="{{ __('orders.ui_switch_reason') }}" dir="auto" class="block w-full rounded-lg border-gray-300">
                    <x-primary-button wire:loading.attr="disabled" wire:target="switchPrimary" class="justify-center">{{ __('orders.ui_switch_primary') }}</x-primary-button>
                    <x-input-error :messages="$errors->get('switchCaptainId')" />
                    <x-input-error :messages="$errors->get('switchReason')" />
                </form>
            </details>
        @endif
        @endcan @endif
    @endcan
</section>
