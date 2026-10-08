<?php

namespace App\Livewire\Orders;

use App\Models\Order;
use App\Models\OrderBillPaymentRequest;
use App\Services\OrderBillPaymentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BillPaymentFeePreview extends Component
{
    #[Locked]
    public int $orderId;

    #[Locked]
    public int $requestId;

    public string $principal = '';

    public function mount(Order $order, OrderBillPaymentRequest $request): void
    {
        $this->orderId = $order->id;
        $this->requestId = $request->id;
    }

    public function render()
    {
        Gate::authorize('bill_payments.record');
        $order = Order::query()->accessibleTo(auth()->user())->findOrFail($this->orderId);
        $request = $order->billPaymentRequests()->findOrFail($this->requestId);
        $preview = null;
        $problem = null;
        if ($this->principal !== '') {
            try {
                $preview = app(OrderBillPaymentService::class)->preview(auth()->user(), $order, $request, $this->principal);
            } catch (ValidationException $exception) {
                $problem = collect($exception->errors())->flatten()->first();
            }
        }

        return view('livewire.orders.bill-payment-fee-preview', compact('request', 'preview', 'problem'));
    }
}
