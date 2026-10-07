<?php

namespace App\Livewire\Orders;

use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingStatus;
use App\Models\Order;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceSnapshotService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderMutationGuard;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class NextAction extends Component
{
    #[Locked]
    public Order $order;

    public function mount(Order $order, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $order);
        $this->order = $order;
    }

    #[On('order-updated')]
    public function refreshOrder(): void
    {
        $this->order = $this->order->fresh();
    }

    public function render()
    {
        $this->order = $this->order->fresh();
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.view');
        $this->order->load(['shops.items', 'shops.purchaseConfirmation', 'shops.receipts', 'captainAssignments', 'currentDeliveryPricing', 'currentInvoice.collection']);

        $guard = app(OrderMutationGuard::class);
        $issued = $this->order->currentInvoice?->status === InvoiceStatus::Issued;
        $collectionAvailability = $this->order->currentInvoice
            ? app(InvoiceCollectionService::class)->availability(auth()->user(), $this->order->currentInvoice) : null;
        $stepProblem = null;
        $transitionProblem = app(OrderStatusService::class)->nextTransitionProblem($this->order);
        $canTransition = Gate::allows('orders.status.update') && $transitionProblem === null;
        $step = match (true) {
            $this->order->operational_status === OrderOperationalStatus::Delivered => 'completed',
            $this->order->operational_status === OrderOperationalStatus::Cancelled => 'cancelled',
            ! $guard->isOperationalEvidenceMutable($this->order) => 'cancellation',
            $issued => $this->issuedStep(),
            $this->order->shops->isEmpty() => 'shop',
            $this->order->shops->contains(fn ($shop) => $shop->items->isEmpty()) => 'products',
            ! $this->order->captainAssignments->contains(fn ($assignment) => $assignment->removed_at === null && $assignment->assignment_role === OrderCaptainRole::PrimaryCollection) => 'captain',
            $this->order->shops->contains(fn ($shop) => ! $shop->purchaseConfirmation) => 'purchase',
            ! $this->order->currentDeliveryPricing => 'pricing',
            $this->order->pricing_status !== OrderPricingStatus::Confirmed => 'actual_prices',
            ! $this->order->currentInvoice => 'invoice',
            $this->order->currentInvoice->status->value === 'DRAFT' => 'invoice_refresh',
            $this->order->currentInvoice->status === InvoiceStatus::Ready && app(InvoiceSnapshotService::class)->currentSourcesAreStale($this->order->currentInvoice) => 'invoice_refresh',
            $this->order->currentInvoice->status->value === 'READY' => 'invoice_issue',
            $this->order->shops->contains(fn ($shop) => ! $shop->picked_up_at || $shop->receipts->isEmpty()) => 'evidence',
            default => 'status',
        };

        if (! $this->canReach($step)) {
            $step = 'waiting';
        }
        if ($step === 'captain' && app(OrderCaptainService::class)->eligibleForOrder(auth()->user(), $this->order)->isEmpty()) {
            $step = 'captain_unavailable';
        }
        if ($step === 'pricing') {
            if (Gate::denies('delivery_pricing.view')) {
                $step = 'waiting';
            } else {
                try {
                    if (! app(OrderDeliveryPricingService::class)->preview(auth()->user(), $this->order)['complete']) {
                        $step = 'pricing_unavailable';
                    }
                } catch (ValidationException) {
                    $step = 'pricing_unavailable';
                }
            }
        }
        if ($step === 'evidence') {
            $needsPickup = $this->order->shops->contains(fn ($shop) => ! $shop->picked_up_at);
            $needsReceipt = $this->order->shops->contains(fn ($shop) => $shop->receipts->isEmpty());
            if (($needsPickup && Gate::denies('orders.update')) || ($needsReceipt && Gate::denies('order_shop_receipts.upload'))) {
                $step = 'waiting';
            }
        }
        if ($step === 'status' && ($stepProblem = $transitionProblem)) {
            $step = 'waiting';
        }

        return view('livewire.orders.next-action', compact('step', 'stepProblem', 'collectionAvailability', 'canTransition'));
    }

    private function canReach(string $step): bool
    {
        $permissions = match ($step) {
            'shop', 'products' => ['orders.update'],
            'captain' => ['orders.captains.assign'],
            'purchase' => ['order_shop_purchases.confirm'],
            'pricing' => ['delivery_pricing.apply', 'delivery_pricing.view'],
            'actual_prices' => ['orders.pricing.update', 'orders.update'],
            'invoice', 'invoice_refresh' => ['invoices.create', 'invoices.view'],
            'invoice_issue' => ['invoices.issue', 'invoices.view'],
            'status' => ['orders.status.update'],
            default => [],
        };

        return collect($permissions)->every(fn ($permission) => Gate::allows($permission));
    }

    private function issuedStep(): string
    {
        return match (true) {
            $this->order->shops->contains(fn ($shop) => ! $shop->purchaseConfirmation) => 'purchase',
            $this->order->shops->contains(fn ($shop) => ! $shop->picked_up_at || $shop->receipts->isEmpty()) => 'evidence',
            default => 'status',
        };
    }
}
