<?php

namespace App\Livewire\Orders;

use App\Enums\OrderItemType;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\Product;
use App\Models\Unit;
use App\Services\OrderItemService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrderShopItems extends Component
{
    use MapsDomainErrors;

    #[Locked]
    public Order $order;

    #[Locked]
    public OrderShop $orderShop;

    public string $itemType = 'CATALOG_ITEM';

    public string $productId = '';

    public string $itemName = '';

    public string $unitId = '';

    public string $quantity = '1';

    public string $estimatedCurrencyId = '';

    public string $manualEstimatedUnitPrice = '';

    public string $productSearch = '';

    public function mount(Order $order, OrderShop $orderShop, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $order);
        abort_if($orderShop->order_id !== $order->id, 404);
        Gate::authorize('orders.update');
        $this->order = $order;
        $this->orderShop = $orderShop;
        $this->selectSingleOptions();
    }

    #[Computed]
    public function products(): Collection
    {
        return Product::query()->where('is_active', true)
            ->when(trim($this->productSearch) !== '', fn (Builder $query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($this->productSearch)).'%']))
            ->orderBy('name')->limit(50)->get();
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function currencies(): Collection
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get();
    }

    public function updatedItemType(string $value): void
    {
        if ($value === OrderItemType::Catalog->value) {
            $this->itemName = '';
        } else {
            $this->productId = '';
        }
        $this->resetValidation(['productId', 'itemName']);
    }

    public function updatedProductSearch(): void
    {
        unset($this->products);
    }

    public function clearDraft(): void
    {
        $this->reset('productId', 'itemName', 'productSearch', 'manualEstimatedUnitPrice');
        $this->quantity = '1';
        $this->resetValidation();
        unset($this->products);
    }

    public function removeItem(int $itemId, OrderItemService $items, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        $item = $this->orderShop->items()->findOrFail($itemId);
        $this->domainAction(fn () => $items->remove(auth()->user(), $this->order, $this->orderShop, $item), ['item' => 'remove']);
        $this->orderShop->load(['items.estimatedCurrency', 'items.actualCurrency']);
        $this->dispatch('order-updated');
        session()->flash('order-item-status-'.$this->orderShop->id, __('orders.flash.item_removed'));
    }

    public function removeShop(OrderShopService $shops, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        $this->domainAction(fn () => $shops->remove(auth()->user(), $this->order, $this->orderShop), ['shop' => 'removeShop']);
        session()->flash('status', __('orders.flash.shop_removed'));
        $this->redirectRoute('orders.show', $this->order);
    }

    public function add(OrderItemService $items, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        $data = $this->validate();
        $this->domainAction(fn () => $items->add(auth()->user(), $this->order, $this->orderShop, [
            'item_type' => $data['itemType'], 'product_id' => $data['productId'] ?: null,
            'item_name' => $data['itemName'] ?: null, 'unit_id' => $data['unitId'],
            'quantity' => $data['quantity'], 'estimated_currency_id' => $data['estimatedCurrencyId'] ?: null,
            'manual_estimated_unit_price' => $data['manualEstimatedUnitPrice'] ?: null,
        ]));
        $this->orderShop->load(['items.estimatedCurrency', 'items.actualCurrency']);
        $this->reset('productId', 'itemName', 'productSearch', 'manualEstimatedUnitPrice');
        $this->quantity = '1';
        unset($this->products);
        $this->dispatch('order-updated');
        $this->dispatch('focus-product-search');
        session()->flash('order-item-status-'.$this->orderShop->id, __('orders.flash.item_added'));
    }

    protected function rules(): array
    {
        return [
            'itemType' => ['required', Rule::enum(OrderItemType::class)],
            'productId' => ['nullable', 'required_if:itemType,CATALOG_ITEM', 'prohibited_if:itemType,ONE_TIME_ITEM', 'integer', 'exists:products,id'],
            'itemName' => ['nullable', 'required_if:itemType,ONE_TIME_ITEM', 'string', 'max:255'],
            'unitId' => ['required', 'integer', 'exists:units,id'],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'estimatedCurrencyId' => ['nullable', 'integer', 'exists:currencies,id'],
            'manualEstimatedUnitPrice' => ['nullable', 'decimal:0,4', 'gt:0'],
        ];
    }

    public function render()
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        abort_if($this->orderShop->order_id !== $this->order->id, 404);

        return view('livewire.orders.order-shop-items');
    }

    private function selectSingleOptions(): void
    {
        if ($this->units->count() === 1) {
            $this->unitId = (string) $this->units->sole()->id;
        }
        if ($this->currencies->count() === 1) {
            $this->estimatedCurrencyId = (string) $this->currencies->sole()->id;
        }
    }
}
