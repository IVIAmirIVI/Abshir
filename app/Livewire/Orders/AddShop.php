<?php

namespace App\Livewire\Orders;

use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopType;
use App\Rules\MobilePhone;
use App\Services\OrderMutationGuard;
use App\Services\OrderService;
use App\Services\OrderShopService;
use App\Services\ShopService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AddShop extends Component
{
    use MapsDomainErrors;
    use SelectsSoleSearchMatch;

    #[Locked]
    public Order $order;

    public string $shopType = '';

    public string $shopId = '';

    public string $businessClassification = '';

    public string $shopName = '';

    public string $shopPhone = '';

    public string $shopAddress = '';

    public string $originAreaId = '';

    public string $notes = '';

    public string $shopSearch = '';

    public string $newShopName = '';

    public string $newShopTypeId = '';

    public string $newShopClassification = '';

    public string $newShopAreaId = '';

    public string $newShopPhone = '';

    public string $newShopAddress = '';

    public function mount(Order $order, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $order);
        Gate::authorize('orders.update');
        $this->order = $order;
    }

    #[Computed]
    public function shops(): Collection
    {
        return $this->shopQuery(true)->get();
    }

    #[Computed]
    public function shopMatches(): Collection
    {
        return $this->shopQuery(false)->get();
    }

    private function shopQuery(bool $includeSelected): Builder
    {
        return Shop::query()->with(['type', 'area'])->accessibleTo(auth()->user())->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereHas('area', fn (Builder $areas) => $areas->where('branch_id', $this->order->branch_id));
                if (auth()->user()->isSuperAdmin()) {
                    $query->orWhereNull('area_id');
                }
            })->when(trim($this->shopSearch) !== '', fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
            ->search($this->shopSearch)->when($includeSelected && $this->shopId !== '', fn (Builder $selected) => $selected->orWhereKey($this->shopId))))
            ->orderBy('name')->limit(50);
    }

    #[Computed]
    public function shopTypes(): Collection
    {
        return ShopType::selectable();
    }

    #[Computed]
    public function areas(): Collection
    {
        return Area::query()->accessibleTo(auth()->user())->where('branch_id', $this->order->branch_id)
            ->where('is_active', true)->with('parent')->orderBy('name')->get();
    }

    public function updatedShopType(string $value): void
    {
        $this->businessClassification = '';
        if ($value === OrderShopType::Registered->value) {
            $this->reset('shopName', 'shopPhone', 'shopAddress', 'originAreaId');
        } elseif ($value === OrderShopType::OneTime->value) {
            $this->reset('shopId');
            if ($this->areas->count() === 1) {
                $this->originAreaId = (string) $this->areas->sole()->id;
            }
        } elseif ($value === 'CREATE_PERMANENT') {
            $this->reset('shopId', 'shopName', 'shopPhone', 'shopAddress', 'businessClassification');
            if ($this->shopTypes->count() === 1) {
                $this->newShopTypeId = (string) $this->shopTypes->sole()->id;
            }
            if ($this->areas->count() === 1) {
                $this->newShopAreaId = (string) $this->areas->sole()->id;
            }
        } else {
            $this->reset('shopId', 'shopName', 'shopPhone', 'shopAddress', 'businessClassification');
        }
        $this->resetValidation();
        $this->validateOnly('shopType');
    }

    public function updatedShopSearch(): void
    {
        unset($this->shops, $this->shopMatches);
    }

    public function selectSoleShop(string $query): void
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        if ($this->shopType === OrderShopType::Registered->value) {
            $this->selectSoleSearchMatch($query, 'shopSearch', 'shopMatches', $this->selectShop(...));
        }
    }

    public function selectShop(int $shopId): void
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        $shop = $this->shopQuery(false)->findOrFail($shopId);
        $this->shopId = (string) $shop->id;
    }

    public function createPermanentShop(ShopService $shops, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        Gate::authorize('shops.create');
        $data = $this->validate([
            'newShopName' => ['required', 'string', 'max:255'],
            'newShopClassification' => ['required', Rule::enum(ShopBusinessClassification::class)],
            'newShopTypeId' => ['nullable', 'integer', 'exists:shop_types,id'],
            'newShopAreaId' => ['required', 'integer', 'exists:areas,id'],
            'newShopPhone' => ['nullable', 'string', 'max:50', new MobilePhone],
            'newShopAddress' => ['nullable', 'string', 'max:5000'],
        ]);
        Area::query()->accessibleTo(auth()->user())->where('branch_id', $this->order->branch_id)
            ->where('is_active', true)->findOrFail($data['newShopAreaId']);
        $shop = $this->domainAction(fn () => DB::transaction(function () use ($shops, $data): Shop {
            $order = Order::query()->lockForUpdate()->findOrFail($this->order->id);
            app(OrderMutationGuard::class)->assertMutableLocked($order);

            return $shops->save(auth()->user(), new Shop, [
                'business_classification' => $data['newShopClassification'], 'name' => $data['newShopName'], 'shop_type_id' => $data['newShopTypeId'] ?: null,
                'area_id' => $data['newShopAreaId'], 'phone' => $data['newShopPhone'] ?: null,
                'address' => $data['newShopAddress'] ?: null, 'is_mobile_visible' => false,
                'accepts_online_orders' => false,
            ]);
        }), ['business_classification' => 'newShopClassification', 'area_id' => 'newShopAreaId', 'shop_type_id' => 'newShopTypeId', 'name' => 'newShopName']);
        $this->shopType = OrderShopType::Registered->value;
        $this->shopId = (string) $shop->id;
        $this->shopSearch = $shop->name;
        unset($this->shops, $this->shopMatches);
        $this->reset('newShopName', 'newShopClassification', 'newShopTypeId', 'newShopAreaId', 'newShopPhone', 'newShopAddress');
        session()->flash('order-action-status', __('orders.flash.permanent_shop_created'));
    }

    public function updatedShopId(): void
    {
        $this->validateOnly('shopId');
    }

    public function updatedBusinessClassification(): void
    {
        $this->validateOnly('businessClassification');
    }

    public function updatedShopName(): void
    {
        $this->validateOnly('shopName');
    }

    public function updatedShopPhone(): void
    {
        $this->validateOnly('shopPhone');
    }

    public function updatedShopAddress(): void
    {
        $this->validateOnly('shopAddress');
    }

    public function add(OrderShopService $shops, OrderService $orders): void
    {
        $orders->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');
        if ($this->shopType === 'CREATE_PERMANENT') {
            throw ValidationException::withMessages(['shopType' => __('orders.ui_create_permanent_first')]);
        }
        $data = $this->validate();
        $this->domainAction(fn () => $shops->add(auth()->user(), $this->order, [
            'shop_type' => $data['shopType'],
            'shop_id' => $data['shopId'] ?: null,
            'business_classification' => $data['businessClassification'] ?: null,
            'shop_name' => $data['shopName'] ?: null,
            'shop_phone' => $data['shopPhone'] ?: null,
            'shop_address' => $data['shopAddress'] ?: null,
            'origin_area_id' => $data['originAreaId'] ?: null,
            'notes' => $data['notes'] ?: null,
        ]));
        session()->flash('status', __('orders.flash.shop_added'));
        $this->redirectRoute('orders.workflow', $this->order);
    }

    protected function rules(): array
    {
        return [
            'shopType' => ['required', Rule::in([OrderShopType::Registered->value, OrderShopType::OneTime->value, 'CREATE_PERMANENT'])],
            'businessClassification' => ['nullable', 'required_if:shopType,ONE_TIME', Rule::enum(ShopBusinessClassification::class)],
            'shopId' => ['nullable', 'required_if:shopType,REGISTERED', 'prohibited_if:shopType,ONE_TIME', 'integer', 'exists:shops,id'],
            'shopName' => ['nullable', 'required_if:shopType,ONE_TIME', 'prohibited_if:shopType,REGISTERED', 'string', 'max:255'],
            'shopPhone' => ['nullable', 'prohibited_if:shopType,REGISTERED', 'string', 'max:50', new MobilePhone],
            'shopAddress' => ['nullable', 'prohibited_if:shopType,REGISTERED', 'string', 'max:500'],
            'originAreaId' => ['nullable', 'required_if:shopType,ONE_TIME', 'prohibited_if:shopType,REGISTERED', 'integer', 'exists:areas,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'shopType' => __('orders.ui_shop_source'), 'shopId' => __('orders.ui_registered_shop'),
            'businessClassification' => __('orders.ui_shop_classification'), 'shopName' => __('orders.ui_one_shop_name'),
            'shopPhone' => __('orders.ui_one_phone'), 'shopAddress' => __('orders.ui_one_address'),
            'originAreaId' => __('orders.ui_shop_area'),
            'notes' => __('common.fields.notes'),
        ];
    }

    public function render()
    {
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.update');

        $orderMutable = ! app(OrderMutationGuard::class)->isPricingFrozen($this->order->fresh());

        return view('livewire.orders.add-shop', compact('orderMutable'));
    }
}
