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
use Livewire\Attributes\Locked;

trait SelectsWizardCaptains
{
    public array $wizardCaptainIds = [];

    public string $wizardCollectorId = '';

    public bool $wizardSecondCaptainEnabled = false;

    #[Locked]
    public bool $wizardCaptainFromOverride = false;

    public function updatedWizardCaptainIds(mixed $value, ?string $key = null): void
    {
        $this->wizardCaptainFromOverride = false;
        if ($key === null) {
            $this->wizardSecondCaptainEnabled = count($this->wizardCaptainIds) > 1;
        }
        $this->wizardCaptainIds = array_filter($this->wizardCaptainIds, fn ($id) => filled($id));
        $this->synchronizeWizardCollector();
    }

    public function addSecondWizardCaptain(): void
    {
        $this->authorizeWizardCaptainControls();
        $this->wizardCaptainFromOverride = false;
        $this->wizardSecondCaptainEnabled = true;
        $this->wizardCollectorId = '';
        if ($this->deliveryOverrideEnabled) {
            $this->deliveryOverrideCaptainId = '';
        }
    }

    public function removeSecondWizardCaptain(): void
    {
        $this->authorizeWizardCaptainControls();
        $this->wizardCaptainFromOverride = false;
        $this->wizardSecondCaptainEnabled = false;
        $this->wizardCaptainIds = filled($this->wizardCaptainIds[0] ?? null) ? [$this->wizardCaptainIds[0]] : [];
        $this->synchronizeWizardCollector();
        $this->resetValidation(['wizardCaptainIds', 'wizardCaptainIds.1', 'wizardCollectorId']);
    }

    private function authorizeWizardCaptainControls(): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('orders.captains.assign');
        Gate::authorize('orders.view');
    }

    private function synchronizeWizardCollector(bool $syncOverride = true): void
    {
        if (count($this->wizardCaptainIds) === 1 && filled($this->wizardCaptainIds[0] ?? null)) {
            $this->wizardCollectorId = (string) $this->wizardCaptainIds[0];
        } elseif (! in_array($this->wizardCollectorId, array_map('strval', $this->wizardCaptainIds), true)) {
            $this->wizardCollectorId = '';
        }
        if ($syncOverride && $this->deliveryOverrideEnabled) {
            $this->deliveryOverrideCaptainId = $this->wizardCollectorId;
        }
    }

    public function updatedWizardCollectorId(): void
    {
        $this->synchronizeWizardCollector();
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
        if ($this->wizardSecondCaptainEnabled && count($this->wizardCaptainIds) !== 2) {
            throw ValidationException::withMessages(['wizardCaptainIds' => __('shared_orders.second_required')]);
        }
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
        $this->synchronizeWizardCollector(false);
        $this->validate(['wizardCaptainIds' => ['required', 'array', 'min:1', 'max:2'],
            'wizardCaptainIds.0' => ['required', 'integer'],
            'wizardCaptainIds.*' => ['required', 'integer', 'distinct'], 'wizardCollectorId' => ['required', 'integer']]);
        unset($this->wizardCaptainCandidates);
        if (! in_array($this->wizardCollectorId, array_map('strval', $this->wizardCaptainIds), true)) {
            throw ValidationException::withMessages(['wizardCollectorId' => __('shared_orders.collector_participant')]);
        }
        $ineligible = array_diff(array_map('intval', $this->wizardCaptainIds), $this->wizardCaptainCandidates->pluck('id')->all());
        if ($ineligible) {
            $errors = ['wizardCaptainIds' => __('domain.captain_is_not_currently_eligible_for_this_order_branch')];
            if ($this->deliveryOverrideEnabled && in_array((int) $this->deliveryOverrideCaptainId, $ineligible, true)) {
                $errors['deliveryOverrideCaptainId'] = $errors['wizardCaptainIds'];
            }
            throw ValidationException::withMessages($errors);
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
