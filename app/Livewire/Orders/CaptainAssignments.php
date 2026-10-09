<?php

namespace App\Livewire\Orders;

use App\Enums\OrderCaptainRole;
use App\Models\Captain;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderCaptainService;
use App\Services\OrderMutationGuard;
use App\Services\OrderService;
use App\Services\SharedPurchaseBoundary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CaptainAssignments extends Component
{
    use MapsDomainErrors;

    #[Locked]
    public Order $order;

    public string $captainId = '';

    public string $assignmentRole = 'PRIMARY_COLLECTION';

    public string $switchCaptainId = '';

    public string $switchReason = '';

    public array $removalReasons = [];

    public array $participantIds = [];

    public string $collectorId = '';

    public string $selectionReason = '';

    #[Locked]
    public string $selectionKey;

    public function mount(Order $order, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $order);
        $this->order = $order;
        $this->selectionKey = (string) Str::uuid();
        $active = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->get();
        $this->participantIds = $active->pluck('captain_id')->map(fn ($id) => (string) $id)->all();
        $this->collectorId = (string) ($active->firstWhere('assignment_role', OrderCaptainRole::PrimaryCollection)?->captain_id ?? '');
        $this->assignmentRole = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection)->exists()
            ? OrderCaptainRole::Support->value : OrderCaptainRole::PrimaryCollection->value;
        if ($this->eligibleCaptains->count() === 1) {
            $this->captainId = (string) $this->eligibleCaptains->sole()->id;
        }
    }

    #[Computed]
    public function eligibleCaptains(): Collection
    {
        $assignedIds = $this->order->captainAssignments()->whereNull('removed_at')->pluck('captain_id')->all();

        return app(OrderCaptainService::class)->eligibleForOrder(auth()->user(), $this->order)
            ->reject(fn (Captain $captain) => in_array($captain->id, $assignedIds, true))
            ->values();
    }

    #[Computed]
    public function participantCandidates(): Collection
    {
        return app(OrderCaptainService::class)->eligibleForOrder(auth()->user(), $this->order);
    }

    public function saveSelection(OrderCaptainSelectionService $selections): void
    {
        $this->validate(['participantIds' => ['required', 'array', 'min:1', 'max:2'],
            'participantIds.*' => ['required', 'integer', 'distinct'], 'collectorId' => ['required', 'integer'],
            'selectionReason' => ['nullable', 'string', 'max:1000']]);
        $this->domainAction(fn () => $selections->select(auth()->user(), $this->order,
            $this->participantIds, (int) $this->collectorId, $this->selectionKey, $this->selectionReason),
            ['captains' => 'participantIds', 'collector' => 'collectorId', 'reason' => 'selectionReason']);
        $this->refreshOrder(__('shared_orders.saved'));
    }

    #[Computed]
    public function switchCandidates(): Collection
    {
        $primaryId = $this->order->captainAssignments()->whereNull('removed_at')
            ->where('assignment_role', OrderCaptainRole::PrimaryCollection)->value('captain_id');

        return app(OrderCaptainService::class)->eligibleForOrder(auth()->user(), $this->order)
            ->reject(fn (Captain $captain) => $captain->id === $primaryId)->values();
    }

    public function assign(OrderCaptainService $captains, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.captains.assign');
        $data = $this->validate([
            'captainId' => ['required', 'integer', 'exists:captains,id'],
            'assignmentRole' => ['required', Rule::enum(OrderCaptainRole::class)],
        ]);
        $this->domainAction(fn () => $captains->assign(auth()->user(), $this->order, Captain::findOrFail($data['captainId']), OrderCaptainRole::from($data['assignmentRole'])));
        $this->refreshOrder(__('orders.flash.captain_assigned'));
    }

    public function switchPrimary(OrderCaptainService $captains, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.captains.assign');
        Gate::authorize('orders.captains.remove');
        $data = $this->validate([
            'switchCaptainId' => ['required', 'integer', 'exists:captains,id'],
            'switchReason' => ['required', 'string', 'max:1000'],
        ]);
        $this->domainAction(fn () => $captains->switchPrimary(auth()->user(), $this->order, Captain::findOrFail($data['switchCaptainId']), $data['switchReason']), ['captain_id' => 'switchCaptainId']);
        $this->refreshOrder(__('orders.flash.captain_switched'));
    }

    public function remove(int $assignmentId, OrderCaptainService $captains, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.captains.remove');
        $this->validate(["removalReasons.{$assignmentId}" => ['required', 'string', 'max:1000']]);
        $assignment = OrderCaptain::query()->where('order_id', $this->order->id)->findOrFail($assignmentId);
        $this->domainAction(fn () => $captains->remove(auth()->user(), $this->order, $assignment, $this->removalReasons[$assignmentId]), ['assignment' => "removalReasons.{$assignmentId}"]);
        unset($this->removalReasons[$assignmentId]);
        $this->refreshOrder(__('orders.flash.captain_removed'));
    }

    private function refreshOrder(string $message): void
    {
        $this->order->load('captainAssignments.captain.user');
        unset($this->eligibleCaptains);
        $this->reset('captainId', 'switchCaptainId', 'switchReason');
        session()->flash('status', $message);
        $this->redirectRoute('orders.workflow', $this->order);
    }

    protected function validationAttributes(): array
    {
        return [
            'captainId' => __('orders.ui_captain_label'),
            'assignmentRole' => __('orders.ui_assignment_role'),
            'switchCaptainId' => __('orders.ui_captain_label'),
            'switchReason' => __('orders.ui_switch_reason'),
            'removalReasons.*' => __('orders.ui_removal_reason'),
        ];
    }

    public function render()
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.view');

        $this->order = $this->order->fresh()->load('captainAssignments.captain.user');
        $orderMutable = ! app(OrderMutationGuard::class)->isPricingFrozen($this->order);
        $selectionAvailable = Schema::hasTable('order_captain_selections');
        $hasSelection = $selectionAvailable && $this->order->captainSelections()->exists();
        $selectionMutable = $orderMutable && ! $this->order->purchaseConfirmations()->exists()
            && ! $this->order->invoices()->exists() && ! $this->order->billPaymentRequests()->exists();
        $sharedSelected = app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($this->order);

        return view('livewire.orders.captain-assignments', compact('orderMutable', 'selectionAvailable', 'hasSelection', 'selectionMutable', 'sharedSelected'));
    }
}
