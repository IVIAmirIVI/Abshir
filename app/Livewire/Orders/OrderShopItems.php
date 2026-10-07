<?php

namespace App\Livewire\Orders;

use App\Enums\OrderItemType;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\Product;
use App\Models\Unit;
use App\Services\InlineOrderProductService;
use App\Services\OrderItemService;
use App\Services\OrderMutationGuard;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrderShopItems extends Component
{
    use MapsDomainErrors;
    use SelectsSoleSearchMatch;

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

    public bool $creatingProduct = false;

    public string $newProductName = '';

    public string $newProductSku = '';

    public string $newProductUnitId = '';

    public string $newProductCategoryId = '';

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
        return $this->productQuery(true)->get();
    }

    #[Computed]
    public function productMatches(): Collection
    {
        return $this->productQuery(false)->get();
    }

    private function productQuery(bool $includeSelected): Builder
    {
        return Product::query()->where('is_active', true)
            ->with('defaultUnit')
            ->when(trim($this->productSearch) !== '', fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->where(fn (Builder $fields) => $fields->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($this->productSearch)).'%'])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.mb_strtolower(trim($this->productSearch)).'%']))
                ->when($includeSelected && $this->productId !== '', fn (Builder $selected) => $selected->orWhereKey($this->productId))))
            ->orderBy('name')->limit(50);
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

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function toggleProductCreation(): void
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('products.create');
        Gate::authorize('orders.update');
        abort_if($this->orderShop->order_id !== $this->order->id, 404);
        app(OrderMutationGuard::class)->assertMutableLocked($this->order->fresh());
        $this->creatingProduct = ! $this->creatingProduct;
        if ($this->creatingProduct) {
            if ($this->newProductName === '') {
                $this->newProductName = trim($this->productSearch);
            }
            if ($this->units->count() === 1) {
                $this->newProductUnitId = (string) $this->units->sole()->id;
            }
            if ($this->categories->count() === 1) {
                $this->newProductCategoryId = (string) $this->categories->sole()->id;
            }
        }
    }

    public function createProduct(InlineOrderProductService $products): void
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        Gate::authorize('products.create');
        abort_if($this->orderShop->order_id !== $this->order->id, 404);
        $this->newProductName = trim($this->newProductName);
        $this->newProductSku = trim($this->newProductSku);
        $fields = ['name' => 'newProductName', 'sku' => 'newProductSku', 'default_unit_id' => 'newProductUnitId', 'category_id' => 'newProductCategoryId'];
        $this->resetValidation(array_values($fields));
        $rules = (new ProductRequest)->rules();
        $this->domainAction(fn () => Validator::make(
            collect($fields)->mapWithKeys(fn ($property, $field) => [$field => $this->{$property}])->all(),
            array_intersect_key($rules, $fields),
        )->validate(), $fields);
        $product = $this->domainAction(fn () => $products->create(auth()->user(), $this->order, $this->orderShop, [
            'name' => $this->newProductName, 'sku' => $this->newProductSku ?: null,
            'default_unit_id' => $this->newProductUnitId, 'category_id' => $this->newProductCategoryId ?: null,
        ]), $fields);
        $this->productId = (string) $product->id;
        $this->productSearch = $product->name;
        if ($this->unitId === '') {
            $this->unitId = (string) $product->default_unit_id;
        }
        $this->creatingProduct = false;
        $this->reset('newProductName', 'newProductSku', 'newProductUnitId', 'newProductCategoryId');
        unset($this->products, $this->productMatches);
        session()->flash('order-item-status-'.$this->orderShop->id, __('orders.ui_product_created'));
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
        unset($this->products, $this->productMatches);
    }

    public function selectSoleProduct(string $query): void
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        if ($this->itemType === OrderItemType::Catalog->value) {
            $this->selectSoleSearchMatch($query, 'productSearch', 'productMatches', $this->selectProduct(...));
        }
    }

    public function selectProduct(int $productId): void
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        $this->productId = (string) $this->productQuery(false)->findOrFail($productId)->id;
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

    protected function validationAttributes(): array
    {
        return [
            'newProductName' => __('common.fields.name'),
            'newProductSku' => __('catalog.sku'),
            'newProductUnitId' => __('catalog.default_unit'),
            'newProductCategoryId' => __('common.terms.category'),
        ];
    }

    public function render()
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        abort_if($this->orderShop->order_id !== $this->order->id, 404);

        $this->order = $this->order->fresh();
        $this->orderShop->refresh()->load(['items.estimatedCurrency', 'items.actualCurrency', 'purchaseConfirmation', 'receipts', 'shopReturn']);
        $orderMutable = ! app(OrderMutationGuard::class)->isPricingFrozen($this->order);
        $removableItemIds = $orderMutable ? $this->orderShop->items
            ->filter(fn ($item) => app(OrderItemService::class)->removalBlockReason($item) === null)->modelKeys() : [];
        $shopRemovable = $orderMutable && app(OrderShopService::class)->removalBlockReason($this->order, $this->orderShop) === null;

        return view('livewire.orders.order-shop-items', compact('orderMutable', 'removableItemIds', 'shopRemovable'));
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
