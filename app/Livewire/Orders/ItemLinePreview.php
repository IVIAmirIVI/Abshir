<?php

namespace App\Livewire\Orders;

use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderLineTotalService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ItemLinePreview extends Component
{
    #[Locked]
    public int $orderId;

    #[Locked]
    public int $shopId;

    #[Locked]
    public int $itemId;

    #[Locked]
    public string $mode;

    public string $quantity = '';

    public string $price = '';

    public string $currencyId = '';

    public function mount(Order $order, OrderItem $item, string $mode): void
    {
        $this->orderId = $order->id;
        $this->shopId = $item->order_shop_id;
        $this->itemId = $item->id;
        $this->mode = $mode;
        $this->quantity = $item->current_quantity;
        $this->price = $mode === 'PRICE' ? ($item->actual_unit_price ?? '') : ($item->actual_unit_price ?? $item->estimated_unit_price ?? '');
        $this->currencyId = (string) ($item->actual_currency_id ?? $item->estimated_currency_id ?? Currency::where('is_active', true)->orderBy('code')->value('id'));
    }

    public function render()
    {
        abort_unless(in_array($this->mode, ['PRICE', 'QUANTITY'], true), 404);
        Gate::authorize($this->mode === 'PRICE' ? 'orders.pricing.update' : 'orders.items.change');
        $order = Order::query()->accessibleTo(auth()->user())->findOrFail($this->orderId);
        $item = $order->shops()->findOrFail($this->shopId)->items()->findOrFail($this->itemId);
        if ($this->mode === 'PRICE') {
            $quantity = $item->current_quantity;
            $price = $this->price;
            $currency = ctype_digit($this->currencyId) ? Currency::where('is_active', true)->find($this->currencyId) : null;
        } else {
            $quantity = $this->quantity;
            $price = $item->actual_unit_price ?? $item->estimated_unit_price;
            $currency = $item->actual_unit_price !== null ? $item->actualCurrency : $item->estimatedCurrency;
        }
        $total = app(OrderLineTotalService::class)->calculate($quantity, $price, $currency);

        return view('livewire.orders.item-line-preview', compact('total', 'currency', 'item'));
    }
}
