<?php

namespace App\Livewire\Orders;

use App\Models\Branch;
use App\Models\Order;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderCaptainService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

trait SelectsWizardCaptains
{
    public array $wizardCaptainIds = [];

    public string $wizardCollectorId = '';

    public function updatedWizardCollectorId(): void
    {
        if ($this->deliveryOverrideEnabled && $this->wizardCaptainIds !== []) {
            $this->deliveryOverrideCaptainId = $this->wizardCollectorId;
        }
    }

    #[Computed]
    public function canSelectWizardCaptains(): bool
    {
        return auth()->user()->can('orders.captains.assign') && auth()->user()->can('orders.view')
            && Schema::hasTable('order_captain_selections');
    }

    #[Computed]
    public function wizardCaptainCandidates(): Collection
    {
        if (! $this->canSelectWizardCaptains || blank($this->branchId)) {
            return collect();
        }

        return app(OrderCaptainService::class)->eligibleForBranch(auth()->user(), Branch::findOrFail($this->branchId));
    }

    private function validateWizardParticipants(): void
    {
        if ($this->wizardCaptainIds === []) {
            if (filled($this->wizardCollectorId)) {
                throw ValidationException::withMessages(['wizardCollectorId' => __('shared_orders.collector_participant')]);
            }

            return;
        }
        Gate::authorize('orders.captains.assign');
        Gate::authorize('orders.view');
        Gate::authorize('delivery_pricing.apply');
        if (! $this->canSelectWizardCaptains) {
            throw ValidationException::withMessages(['wizardCaptainIds' => __('shared_orders.activation_required')]);
        }
        $this->validate(['wizardCaptainIds' => ['required', 'array', 'min:1', 'max:2'],
            'wizardCaptainIds.*' => ['required', 'integer', 'distinct'], 'wizardCollectorId' => ['required', 'integer']]);
        unset($this->wizardCaptainCandidates);
        if (! in_array($this->wizardCollectorId, array_map('strval', $this->wizardCaptainIds), true)) {
            throw ValidationException::withMessages(['wizardCollectorId' => __('shared_orders.collector_participant')]);
        }
        if (array_diff(array_map('intval', $this->wizardCaptainIds), $this->wizardCaptainCandidates->pluck('id')->all())) {
            throw ValidationException::withMessages(['wizardCaptainIds' => __('domain.captain_is_not_currently_eligible_for_this_order_branch')]);
        }
        $this->requireCaptainShare();
        if ($this->deliveryOverrideEnabled && $this->deliveryOverrideCaptainId !== $this->wizardCollectorId) {
            throw ValidationException::withMessages(['deliveryOverrideCaptainId' => __('shared_orders.override_collector')]);
        }
    }

    private function wizardParticipantReview(): array
    {
        return ['captain_ids' => collect($this->wizardCaptainIds)->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'collector_id' => $this->wizardCollectorId];
    }

    private function persistWizardParticipants(Order $order): void
    {
        if ($this->wizardCaptainIds !== []) {
            $this->domainAction(fn () => app(OrderCaptainSelectionService::class)->select(auth()->user(), $order,
                $this->wizardCaptainIds, (int) $this->wizardCollectorId, $this->submissionKey),
                ['captains' => 'wizardCaptainIds', 'collector' => 'wizardCollectorId']);
        }
    }

    #[Computed]
    public function wizardCombinedDelivery(): ?string
    {
        $proposal = $this->deliveryProposal;
        if (! isset($proposal['suggested_unit_fee'])) {
            return null;
        }
        $fee = $this->deliveryOverrideEnabled ? ($this->deliveryOverrideOutcome['final_fee'] ?? null) : $proposal['suggested_unit_fee'];

        return $fee === null ? null : (string) BigDecimal::of($fee)->multipliedBy(max(1, count($this->wizardCaptainIds)));
    }
}
