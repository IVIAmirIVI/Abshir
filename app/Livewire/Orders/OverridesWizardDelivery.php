<?php

namespace App\Livewire\Orders;

use App\Enums\OrderCaptainRole;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Order;
use App\Services\DeliveryPricingSettings;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait OverridesWizardDelivery
{
    #[Locked]
    public bool $deliveryOverrideEnabled = false;

    public string $deliveryOverrideAmount = '';

    public string $deliveryOverrideReason = '';

    public string $deliveryOverrideCaptainId = '';

    #[Locked]
    public string $deliveryOverrideContext = '';

    #[Locked]
    public array $reviewedDelivery = [];

    #[Computed]
    public function canUseDeliveryOverride(): bool
    {
        return collect(['delivery_pricing.override', 'delivery_pricing.apply', 'orders.captains.assign', 'orders.view'])
            ->every(fn ($permission) => auth()->user()->can($permission));
    }

    private function authorizeWizardOverride(): void
    {
        foreach (['delivery_pricing.override', 'delivery_pricing.apply', 'orders.captains.assign', 'orders.view'] as $permission) {
            Gate::authorize($permission);
        }
    }

    #[Computed]
    public function overrideCaptains(): Collection
    {
        if (! $this->canUseDeliveryOverride || ! $this->branchId) {
            return collect();
        }

        return app(OrderCaptainService::class)->eligibleForBranch(auth()->user(), Branch::findOrFail($this->branchId));
    }

    public function beginDeliveryOverride(): void
    {
        $this->authorizeWizardOverride();
        $proposal = $this->requireDeliveryProposal();
        $this->requireCaptainShare();
        $this->deliveryOverrideEnabled = true;
        $this->deliveryOverrideContext = $this->deliveryContext($proposal);
        if ($this->wizardCaptainIds !== [] && in_array($this->wizardCollectorId, array_map('strval', $this->wizardCaptainIds), true)) {
            $this->deliveryOverrideCaptainId = $this->wizardCollectorId;
        } elseif ($this->overrideCaptains->count() === 1) {
            $this->deliveryOverrideCaptainId = (string) $this->overrideCaptains->sole()->id;
            if ($this->canSelectWizardCaptains && $this->wizardCaptainIds === []) {
                $this->wizardCaptainIds = [$this->deliveryOverrideCaptainId];
                $this->wizardCaptainFromOverride = true;
                $this->synchronizeWizardCollector();
            }
        }
        $this->resetValidation();
    }

    public function restoreAutomaticDelivery(): void
    {
        Gate::authorize('orders.create');
        if ($this->wizardCaptainFromOverride) {
            $this->reset('wizardCaptainIds', 'wizardCollectorId', 'wizardSecondCaptainEnabled', 'wizardCaptainFromOverride');
        }
        $this->deliveryOverrideEnabled = false;
        $this->reset('deliveryOverrideAmount', 'deliveryOverrideReason', 'deliveryOverrideCaptainId', 'deliveryOverrideContext', 'reviewedDelivery');
        $this->resetValidation();
    }

    public function reconfirmDeliveryOverride(): void
    {
        $this->authorizeWizardOverride();
        $this->deliveryOverrideContext = $this->deliveryContext($this->requireDeliveryProposal());
        $this->reviewedDelivery = [];
        $this->resetValidation();
    }

    private function requireCaptainShare(): void
    {
        if (! app(DeliveryPricingSettings::class)->captainSharePercent()?->isGreaterThan(0)) {
            throw ValidationException::withMessages(['deliveryOverrideAmount' => __('domain.captain_delivery_share_percentage_must_be_configured')]);
        }
    }

    /** Pricing dimensions only; product quantities do not change the route fee. */
    private function deliveryContext(array $proposal): string
    {
        $decimal = fn ($value) => $value === null ? null : (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();

        return hash('sha256', json_encode([
            $this->branchId, $this->customerId, $this->customerAddressId,
            $proposal['currency']->id, $proposal['currency']->decimal_places,
            $proposal['routes']->map(fn ($route) => [$route['origin']?->id, $route['destination']->id,
                $route['rule']?->id, $decimal($route['proposed_unit_fee'])])->values()->all(),
            $proposal['is_multi_shop'],
            $this->deliveryOverrideEnabled ? $decimal(app(DeliveryPricingSettings::class)->captainSharePercent()) : null,
            $this->deliveryOverrideEnabled ? $decimal(app(DeliveryPricingSettings::class)->overrideThresholdPercent()) : null,
        ], JSON_THROW_ON_ERROR));
    }

    #[Computed]
    public function deliveryOverrideOutcome(): array
    {
        if (! $this->deliveryOverrideEnabled) {
            return [];
        }
        try {
            $proposal = $this->requireDeliveryProposal();
            if ($this->deliveryOverrideContext !== $this->deliveryContext($proposal)) {
                return ['error' => __('wizard_delivery.context_changed'), 'context_changed' => true];
            }
            if ($this->deliveryOverrideAmount === '') {
                return [];
            }
            if (! preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/', $this->deliveryOverrideAmount)) {
                return ['error' => __('domain.applied_fee_must_be_positive')];
            }
            $decision = $this->domainAction(fn () => app(OrderDeliveryPricingService::class)->overrideDecision(auth()->user(),
                $proposal['currency'], $proposal['suggested_unit_fee'], $this->deliveryOverrideAmount));

            return ['immediate' => $decision['immediate'], 'requested_fee' => (string) $decision['requested'],
                'final_fee' => (string) ($decision['immediate'] ? $decision['requested'] : $decision['suggested'])];
        } catch (ValidationException $exception) {
            return ['error' => collect($exception->errors())->flatten()->first()];
        }
    }

    private function validateDeliveryOverride(array $proposal): array
    {
        $this->authorizeWizardOverride();
        $this->requireCaptainShare();
        if ($this->deliveryOverrideContext !== $this->deliveryContext($proposal)) {
            throw ValidationException::withMessages(['deliveryOverrideAmount' => __('wizard_delivery.context_changed')]);
        }
        $this->validate([
            'deliveryOverrideAmount' => ['required', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'deliveryOverrideReason' => ['required', 'string', 'max:3000'],
            'deliveryOverrideCaptainId' => ['required', 'integer'],
        ]);
        if (trim($this->deliveryOverrideReason) === '') {
            throw ValidationException::withMessages(['deliveryOverrideReason' => __('domain.override_reason_is_required')]);
        }
        unset($this->overrideCaptains);
        if (! $this->overrideCaptains->contains('id', $this->deliveryOverrideCaptainId)) {
            throw ValidationException::withMessages(['deliveryOverrideCaptainId' => __('domain.captain_is_not_currently_eligible_for_this_order_branch')]);
        }
        $decision = $this->domainAction(fn () => app(OrderDeliveryPricingService::class)->overrideDecision(auth()->user(),
            $proposal['currency'], $proposal['suggested_unit_fee'], $this->deliveryOverrideAmount), ['requested_unit_fee' => 'deliveryOverrideAmount']);

        return ['immediate' => $decision['immediate'],
            'final_fee' => (string) ($decision['immediate'] ? $decision['requested'] : $decision['suggested']),
            'requested_fee' => (string) $decision['requested']];
    }

    private function deliveryReview(array $proposal): array
    {
        $outcome = $this->deliveryOverrideEnabled ? $this->validateDeliveryOverride($proposal) : ['final_fee' => $proposal['suggested_unit_fee']];

        return $outcome + ['context' => $this->deliveryContext($proposal), 'override' => $this->deliveryOverrideEnabled,
            'participants' => $this->wizardParticipantReview(),
            'captain_id' => $this->deliveryOverrideEnabled ? $this->deliveryOverrideCaptainId : '',
            'reason' => $this->deliveryOverrideEnabled ? trim($this->deliveryOverrideReason) : ''];
    }

    private function assertReviewedDelivery(array $proposal): void
    {
        if ($this->reviewedDelivery !== $this->deliveryReview($proposal)) {
            throw ValidationException::withMessages(['delivery' => __('wizard_delivery.review_changed')]);
        }
    }

    private function persistWizardOverride(Order $order, array $proposal): void
    {
        $this->assertReviewedDelivery($proposal);
        if (! $this->deliveryOverrideEnabled) {
            return;
        }
        if ($this->wizardCaptainIds === [] && ! $order->captainAssignments()->whereNull('removed_at')
            ->where('captain_id', $this->deliveryOverrideCaptainId)->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->exists()) {
            $this->domainAction(fn () => app(OrderCaptainService::class)->assign(auth()->user(), $order,
                Captain::findOrFail($this->deliveryOverrideCaptainId), OrderCaptainRole::PrimaryCollection), ['captain_id' => 'deliveryOverrideCaptainId']);
        }
        $pricing = app(OrderDeliveryPricingService::class);
        if (! $this->reviewedDelivery['immediate']) {
            $pricing->price(auth()->user(), $order, $proposal['origin']);
        }
        $this->domainAction(fn () => $pricing->requestOverride(auth()->user(), $order, $proposal['origin'],
            $this->deliveryOverrideAmount, trim($this->deliveryOverrideReason)), ['requested_unit_fee' => 'deliveryOverrideAmount', 'reason' => 'deliveryOverrideReason']);
        // Fail closed if rules or approval settings changed while creation was in flight.
        $this->assertReviewedDelivery($pricing->preview(auth()->user(), $order));
        $current = $order->currentDeliveryPricing()->with(['routes' => fn ($query) => $query->orderBy('id')])->firstOrFail();
        $expectedRoutes = $proposal['routes']->map(fn ($route) => $route['rule']->id)->values()->all();
        if (! BigDecimal::of($current->applied_unit_fee)->isEqualTo($this->reviewedDelivery['final_fee'])
            || $current->routes->pluck('delivery_pricing_rule_id')->all() !== $expectedRoutes) {
            throw ValidationException::withMessages(['delivery' => __('wizard_delivery.review_changed')]);
        }
    }
}
