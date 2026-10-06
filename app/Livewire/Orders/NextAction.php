<?php

namespace App\Livewire\Orders;

use App\Enums\OrderCaptainRole;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\Gate;
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
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.view');
        $this->order->load(['shops.items', 'shops.purchaseConfirmation', 'shops.receipts', 'captainAssignments', 'currentDeliveryPricing', 'currentInvoice.collection']);

        $step = match (true) {
            $this->order->shops->isEmpty() => 'shop',
            $this->order->shops->contains(fn ($shop) => $shop->items->isEmpty()) => 'products',
            ! $this->order->captainAssignments->contains(fn ($assignment) => $assignment->removed_at === null && $assignment->assignment_role === OrderCaptainRole::PrimaryCollection) => 'captain',
            $this->order->shops->contains(fn ($shop) => ! $shop->purchaseConfirmation) => 'purchase',
            ! $this->order->currentDeliveryPricing => 'pricing',
            ! $this->order->currentInvoice => 'invoice',
            $this->order->currentInvoice->status->value === 'DRAFT' => 'invoice_refresh',
            $this->order->currentInvoice->status->value === 'READY' => 'invoice_issue',
            $this->order->currentInvoice->status->value === 'ISSUED' && ! $this->order->currentInvoice->collection => 'collect',
            $this->order->shops->contains(fn ($shop) => ! $shop->picked_up_at || $shop->receipts->isEmpty()) => 'evidence',
            default => 'status',
        };

        return view('livewire.orders.next-action', compact('step'));
    }
}
