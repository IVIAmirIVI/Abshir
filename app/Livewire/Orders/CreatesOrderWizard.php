<?php

namespace App\Livewire\Orders;

use App\Enums\ShopBusinessClassification;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Rules\MobilePhone;
use App\Services\CustomerAddressService;
use App\Services\CustomerPhoneService;
use App\Services\InlineOrderProductService;
use App\Services\OrderCreationDraftService;
use App\Services\ShopService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait CreatesOrderWizard
{
    #[Locked]
    public int $step = 1;

    #[Locked]
    public string $submissionKey;

    #[Locked]
    public ?int $createdOrderId = null;

    #[Locked]
    public array $groups = [];

    public array $specialDeliveryAssignments = [];

    public bool $specialProductsOnly = false;

    #[Locked]
    public array $itemDrafts = [];

    public string $shopSearch = '';

    public string $shopId = '';

    public string $shopType = 'REGISTERED';

    public string $businessClassification = '';

    public string $shopName = '';

    public string $shopPhone = '';

    public string $shopAddress = '';

    public string $originAreaId = '';

    public string $activeGroup = '';

    public string $productSearch = '';

    public string $productId = '';

    public string $itemType = 'CATALOG_ITEM';

    public string $itemName = '';

    public string $unitId = '';

    public string $quantity = '1';

    public bool $showShopCreate = false;

    public string $newShopName = '';

    public string $newShopAreaId = '';

    public string $newShopTypeId = '';

    public string $newShopClassification = '';

    #[Computed]
    public function knownShopClassification(): ?ShopBusinessClassification
    {
        return $this->shopType === 'REGISTERED' && $this->shopId !== ''
            ? $this->accessibleShop($this->shopId)->business_classification : null;
    }

    public string $newShopPhone = '';

    public string $newShopAddress = '';

    public bool $showProductCreate = false;

    public string $newProductName = '';

    public string $newProductSku = '';

    public string $newProductUnitId = '';

    public string $newProductCategoryId = '';

    public bool $showAddressCreate = false;

    public bool $showPhoneCreate = false;

    public string $newPhoneNumber = '';

    public bool $newPhoneDuplicateAcknowledged = false;

    public string $newAddressLine = '';

    public string $newAddressAreaId = '';

    public function goToStep(int $step): void
    {
        Gate::authorize('orders.create');
        abort_unless($step >= 1 && $step <= 4, 404);
        if ($step > $this->step || $step === 4) {
            for ($index = 1; $index < $step; $index++) {
                $this->validateWizardStep($index);
            }
        }
        if ($step === 4) {
            $this->reviewedDelivery = $this->deliveryReview($this->requireDeliveryProposal());
        }
        $this->step = $step;
        $this->resetValidation();
    }

    public function nextStep(?int $fromStep = null): void
    {
        // A queued second click from the old step must not advance the new one.
        if ($fromStep !== null && $fromStep !== $this->step) {
            return;
        }
        $this->goToStep(min(4, $this->step + 1));
    }

    private function validateWizardStep(int $step): void
    {
        try {
            if ($step === 1) {
                $data = $this->validate($this->rules());
                app(OrderCreationDraftService::class)->contacts(auth()->user(), $data);
            } elseif ($step === 2) {
                app(OrderCreationDraftService::class)->groups(auth()->user(), $this->branchId, $this->groups);
            } elseif ($step === 3) {
                $this->validateWizardParticipants();
                $proposal = $this->requireDeliveryProposal();
                if ($proposal['special_incomplete'] ?? false) {
                    throw ValidationException::withMessages(['special_delivery' => __('special.assign_portions')]);
                }
                if ($this->deliveryOverrideEnabled) {
                    $this->validateDeliveryOverride($proposal);
                }
            }
        } catch (ValidationException $exception) {
            $this->step = $step;
            throw $exception;
        }
    }

    private function requireDeliveryProposal(): array
    {
        $address = app(OrderCreationDraftService::class)->contacts(auth()->user(), $this->validate($this->rules()));
        $proposal = app(OrderCreationDraftService::class)->preview(auth()->user(), $this->branchId, $address, $this->groups, $this->wizardCaptainIds, $this->specialDeliveryAssignments);
        if (! $proposal['complete']) {
            throw ValidationException::withMessages(['delivery' => __('orders.wizard.missing_route')]);
        }

        return $proposal;
    }

    #[Computed]
    public function reviewGroups(): array
    {
        $result = app(OrderCreationDraftService::class)->groups(auth()->user(), $this->branchId, $this->groups);
        $products = Product::query()->whereIn('id', collect($this->groups)->pluck('items')->flatten(1)->pluck('product_id')->filter())->get()->keyBy('id');
        $units = $this->units->keyBy('id');

        return collect($result)->map(function ($row) use ($products, $units) {
            $group = $row['data'];
            $group['label'] = $row['name'];
            $group['area_label'] = $row['origin']->name;
            foreach ($group['items'] as &$item) {
                $item['label'] = $item['item_type'] === 'CATALOG_ITEM' ? $products[$item['product_id']]->name : $item['item_name'];
                $item['unit_label'] = $units[$item['unit_id']]->name;
            }

            return $group;
        })->all();
    }

    #[Computed]
    public function deliveryProposal(): array
    {
        if ($this->step < 3) {
            return [];
        }
        try {
            return $this->requireDeliveryProposal();
        } catch (ValidationException $exception) {
            return ['error' => collect($exception->errors())->flatten()->first()];
        }
    }

    #[Computed]
    public function eligibleGroupKeys(): array
    {
        $ids = Shop::query()->accessibleTo(auth()->user())->where('is_active', true)
            ->whereIn('id', collect($this->groups)->pluck('shop_id')->filter())
            ->whereHas('area', fn ($q) => $q->where('branch_id', $this->branchId)->where('is_active', true))
            ->pluck('id')->all();

        return array_keys(array_filter($this->groups, fn ($group) => $group['shop_type'] === 'REGISTERED'
            ? in_array($group['shop_id'], $ids, true)
            : $this->areas->contains('id', $group['origin_area_id'])));
    }

    private function accessibleShop(int|string $id): Shop
    {
        return Shop::query()->accessibleTo(auth()->user())->where('is_active', true)->with('type', 'area')
            ->whereHas('area', fn ($q) => $q->where('branch_id', $this->branchId)->where('is_active', true))->findOrFail($id);
    }

    #[Computed]
    public function shopMatches(): Collection
    {
        return Shop::query()->accessibleTo(auth()->user())->where('is_active', true)->with('area', 'type')
            ->whereHas('area', fn ($q) => $q->where('branch_id', $this->branchId)->where('is_active', true))
            ->when(trim($this->shopSearch) !== '', fn ($q) => $q->search(trim($this->shopSearch)))
            ->orderBy('name')->limit(20)->get();
    }

    public function updatedShopSearch(): void
    {
        unset($this->shopMatches);
    }

    public function updatedProductSearch(): void
    {
        unset($this->productMatches);
    }

    public function selectShop(int $id): void
    {
        Gate::authorize('orders.create');
        $shop = $this->accessibleShop($id);
        $this->shopId = (string) $shop->id;
        $this->shopSearch = $shop->name;
        $this->businessClassification = $shop->business_classification?->value ?? '';
        unset($this->knownShopClassification);
        unset($this->shopMatches);
    }

    public function selectSoleShop(string $query): void
    {
        Gate::authorize('orders.create');
        $this->selectSoleSearchMatch($query, 'shopSearch', 'shopMatches', $this->selectShop(...));
    }

    public function addDraftShop(): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('orders.update');
        $this->validate([
            'shopType' => ['required', 'in:REGISTERED,ONE_TIME'],
            'shopId' => ['nullable', 'required_if:shopType,REGISTERED', 'integer'],
            'shopName' => ['nullable', 'required_if:shopType,ONE_TIME', 'string', 'max:255'],
            'shopPhone' => ['nullable', 'string', 'max:50', new MobilePhone],
            'shopAddress' => ['nullable', 'string', 'max:500'],
            'originAreaId' => ['nullable', 'required_if:shopType,ONE_TIME', 'integer'],
        ]);
        if (count($this->groups) >= 20) {
            throw ValidationException::withMessages(['shopId' => __('orders.wizard.shop_limit')]);
        }
        $shop = $this->shopType === 'REGISTERED' ? $this->accessibleShop($this->shopId) : null;
        if ($shop) {
            foreach ($this->groups as $key => $group) {
                if ($group['shop_type'] === 'REGISTERED' && (string) $group['shop_id'] === (string) $shop->id) {
                    $this->editGroup($key);

                    return;
                }
            }
        }
        $classification = $shop ? $shop->business_classification?->value : $this->businessClassification;
        if ($shop && ! $classification) {
            throw ValidationException::withMessages(['shopId' => __('orders.wizard.missing_master_classification')]);
        }
        if ($shop && filled($this->businessClassification) && $this->businessClassification !== $classification) {
            throw ValidationException::withMessages(['shopId' => __('orders.wizard.master_classification_conflict')]);
        }
        if (! $shop) {
            $this->validate(['businessClassification' => ['required', 'in:RESTAURANT,SHOP']]);
        }
        if (! $shop) {
            $origin = $this->areas->firstWhere('id', $this->originAreaId);
            abort_unless($origin, 404);
        }
        $key = (string) Str::uuid();
        $this->groups[$key] = [
            'shop_type' => $this->shopType, 'shop_id' => $shop?->id,
            'shop_name' => $shop ? null : $this->shopName, 'shop_phone' => $shop ? null : ($this->shopPhone ?: null),
            'shop_address' => $shop ? null : ($this->shopAddress ?: null),
            'origin_area_id' => $shop ? null : $origin->id, 'business_classification' => $classification,
            'label' => $shop?->name ?? $this->shopName, 'area_label' => $shop?->area->name ?? $origin->name, 'items' => [],
        ];
        $this->editGroup($key);
        $this->reset('shopId', 'shopSearch', 'shopName', 'shopPhone', 'shopAddress', 'businessClassification');
        $this->resetValidation('groups');
    }

    public function updatedShopType(): void
    {
        $this->originAreaId = $this->areas->count() === 1 ? (string) $this->areas->sole()->id : '';
    }

    public function editGroup(string $key): void
    {
        $this->draftGroup($key);
        $fields = ['productId', 'productSearch', 'itemType', 'itemName', 'unitId', 'quantity'];
        if ($this->activeGroup !== '' && isset($this->groups[$this->activeGroup])) {
            foreach ($fields as $field) {
                $this->itemDrafts[$this->activeGroup][$field] = $this->{$field};
            }
        }
        $this->activeGroup = $key;
        foreach ($fields as $field) {
            $this->{$field} = $this->itemDrafts[$key][$field] ?? match ($field) {
                'itemType' => 'CATALOG_ITEM', 'quantity' => '1',
                'unitId' => $this->units->count() === 1 ? (string) $this->units->sole()->id : '', default => '',
            };
        }
        unset($this->productMatches);
    }

    private function draftGroup(string $key): array
    {
        Gate::authorize('orders.create');
        abort_unless(isset($this->groups[$key]), 404);
        $group = $this->groups[$key];
        if ($group['shop_type'] === 'REGISTERED') {
            $this->accessibleShop($group['shop_id']);
        } else {
            abort_unless($this->areas->contains('id', $group['origin_area_id']), 404);
        }

        return $group;
    }

    public function removeDraftShop(string $key): void
    {
        Gate::authorize('orders.create');
        abort_unless(isset($this->groups[$key]), 404);
        unset($this->groups[$key], $this->itemDrafts[$key]);
        if ($this->activeGroup === $key) {
            $this->activeGroup = '';
            if ($next = ($this->eligibleGroupKeys[0] ?? null)) {
                $this->editGroup($next);
            } else {
                $this->reset('productId', 'productSearch', 'itemName');
            }
        }
    }

    public function removeDraftItem(string $key, int $index): void
    {
        $this->draftGroup($key);
        abort_unless(isset($this->groups[$key]['items'][$index]), 404);
        array_splice($this->groups[$key]['items'], $index, 1);
        foreach (array_keys($this->specialDeliveryAssignments) as $sourceKey) {
            if (str_starts_with($sourceKey, $key.'_')) {
                unset($this->specialDeliveryAssignments[$sourceKey]);
            }
        }
    }

    #[Computed]
    public function productMatches(): Collection
    {
        if (! $this->activeGroup || ! in_array($this->activeGroup, $this->eligibleGroupKeys, true)) {
            return collect();
        }
        $this->draftGroup($this->activeGroup);

        // Catalog Products are global in the existing OrderItemService contract.
        return Product::query()->where('is_active', true)->with('defaultUnit')
            ->when($this->specialProductsOnly, fn ($q) => $q->where('is_special', true))
            ->when(trim($this->productSearch) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($this->productSearch)).'%'])
                ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.mb_strtolower(trim($this->productSearch)).'%'])))
            ->orderBy('name')->limit(20)->get();
    }

    public function selectProduct(int $id): void
    {
        $this->draftGroup($this->activeGroup);
        $product = Product::query()->where('is_active', true)->findOrFail($id);
        $this->productId = (string) $id;
        $this->productSearch = $product->name;
        $this->unitId = (string) $product->default_unit_id;
    }

    public function selectSoleProduct(string $query): void
    {
        $this->draftGroup($this->activeGroup);
        $this->selectSoleSearchMatch($query, 'productSearch', 'productMatches', $this->selectProduct(...));
    }

    public function addDraftItem(): void
    {
        $this->draftGroup($this->activeGroup);
        $this->validate([
            'itemType' => ['required', 'in:CATALOG_ITEM,ONE_TIME_ITEM'],
            'productId' => ['nullable', 'required_if:itemType,CATALOG_ITEM', 'integer', 'exists:products,id'],
            'itemName' => ['nullable', 'required_if:itemType,ONE_TIME_ITEM', 'string', 'max:255'],
            'unitId' => ['required', 'integer', 'exists:units,id'], 'quantity' => ['required', 'decimal:0,4', 'gt:0'],
        ]);
        $product = $this->itemType === 'CATALOG_ITEM' ? Product::query()->where('is_active', true)->findOrFail($this->productId) : null;
        $unit = Unit::query()->where('is_active', true)->findOrFail($this->unitId);
        if (! $unit->allows_decimal && ! preg_match('/^\d+(?:\.0{1,4})?$/', $this->quantity)) {
            throw ValidationException::withMessages(['quantity' => __('domain.this_unit_requires_a_whole_number_quantity')]);
        }
        if (count($this->groups[$this->activeGroup]['items']) >= 100) {
            throw ValidationException::withMessages(['productId' => __('orders.wizard.item_limit')]);
        }
        $this->groups[$this->activeGroup]['items'][] = [
            'item_type' => $this->itemType, 'product_id' => $product?->id, 'item_name' => $product ? null : $this->itemName,
            'unit_id' => $unit->id, 'quantity' => $this->quantity, 'label' => $product?->name ?? $this->itemName, 'unit_label' => $unit->name,
        ];
        $this->reset('productId', 'productSearch', 'itemName');
        $this->quantity = '1';
        unset($this->itemDrafts[$this->activeGroup], $this->productMatches);
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function shopTypes(): Collection
    {
        return ShopType::selectable();
    }

    public function openShopCreate(): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('shops.create');
        $this->showShopCreate = true;
        if ($this->areas->count() === 1) {
            $this->newShopAreaId = (string) $this->areas->sole()->id;
        }
    }

    public function createPermanentShop(ShopService $shops): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('shops.create');
        $data = $this->validate([
            'newShopName' => ['required', 'string', 'max:255'], 'newShopAreaId' => ['required', 'integer'],
            'newShopTypeId' => ['nullable', 'integer', 'exists:shop_types,id'],
            'newShopClassification' => ['required', Rule::enum(ShopBusinessClassification::class)],
            'newShopPhone' => ['nullable', 'string', 'max:50', new MobilePhone], 'newShopAddress' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless($this->areas->contains('id', $data['newShopAreaId']), 404);
        $shop = $this->domainAction(fn () => $shops->save(auth()->user(), new Shop, [
            'business_classification' => $data['newShopClassification'], 'name' => $data['newShopName'], 'area_id' => $data['newShopAreaId'], 'shop_type_id' => $data['newShopTypeId'] ?: null,
            'phone' => $data['newShopPhone'] ?: null, 'address' => $data['newShopAddress'] ?: null,
        ]), ['name' => 'newShopName', 'area_id' => 'newShopAreaId', 'shop_type_id' => 'newShopTypeId']);
        $this->shopType = 'REGISTERED';
        $this->selectShop($shop->id);
        $this->businessClassification = $shop->business_classification->value;
        $this->showShopCreate = false;
        $this->reset('newShopName', 'newShopAreaId', 'newShopTypeId', 'newShopClassification', 'newShopPhone', 'newShopAddress');
    }

    public function openProductCreate(): void
    {
        $this->draftGroup($this->activeGroup);
        Gate::authorize('products.create');
        $this->showProductCreate = true;
        if ($this->units->count() === 1) {
            $this->newProductUnitId = (string) $this->units->sole()->id;
        }
    }

    public function createProduct(InlineOrderProductService $products): void
    {
        Gate::authorize('products.create');
        $group = $this->draftGroup($this->activeGroup);
        $shop = $group['shop_type'] === 'REGISTERED' ? $this->accessibleShop($group['shop_id']) : null;
        $product = $this->domainAction(fn () => $products->createForDraft(auth()->user(), $shop, [
            'name' => $this->newProductName, 'sku' => $this->newProductSku ?: null,
            'default_unit_id' => $this->newProductUnitId, 'category_id' => $this->newProductCategoryId ?: null,
        ]), ['name' => 'newProductName', 'sku' => 'newProductSku', 'default_unit_id' => 'newProductUnitId', 'category_id' => 'newProductCategoryId']);
        $this->itemType = 'CATALOG_ITEM';
        $this->selectProduct($product->id);
        $this->showProductCreate = false;
        $this->reset('newProductName', 'newProductSku', 'newProductUnitId', 'newProductCategoryId');
        unset($this->productMatches);
    }

    public function openAddressCreate(): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('customers.update');
        Customer::query()->accessibleTo(auth()->user())->where('is_active', true)->findOrFail($this->customerId);
        $this->showAddressCreate = true;
        if ($this->areas->count() === 1) {
            $this->newAddressAreaId = (string) $this->areas->sole()->id;
        }
    }

    public function openPhoneCreate(): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('customers.update');
        Customer::query()->accessibleTo(auth()->user())->where('is_active', true)->findOrFail($this->customerId);
        $this->showPhoneCreate = true;
    }

    public function createPhone(CustomerPhoneService $phones): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('customers.update');
        $customer = Customer::query()->accessibleTo(auth()->user())->where('is_active', true)->findOrFail($this->customerId);
        $this->validate(['newPhoneNumber' => ['required', 'string', new MobilePhone(true)]]);
        $phone = $this->domainAction(fn () => $phones->save(auth()->user(), $customer, new CustomerPhone, [
            'phone' => $this->newPhoneNumber, 'mobile_only' => true,
            'duplicate_acknowledged' => $this->newPhoneDuplicateAcknowledged,
        ]), ['phone' => 'newPhoneNumber']);
        unset($this->phones);
        $this->customerPhoneId = (string) $phone->id;
        $this->showPhoneCreate = false;
        $this->reset('newPhoneNumber', 'newPhoneDuplicateAcknowledged');
    }

    public function createAddress(CustomerAddressService $addresses): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('customers.update');
        $this->validate(['newAddressLine' => ['required', 'string', 'max:500'], 'newAddressAreaId' => ['required', 'integer']]);
        $customer = Customer::query()->accessibleTo(auth()->user())->where('is_active', true)->findOrFail($this->customerId);
        abort_unless($this->areas->contains('id', $this->newAddressAreaId), 404);
        $address = $this->domainAction(fn () => $addresses->save(auth()->user(), $customer, new CustomerAddress, [
            'area_id' => $this->newAddressAreaId, 'address_line_1' => $this->newAddressLine,
        ]), ['area_id' => 'newAddressAreaId', 'address_line_1' => 'newAddressLine']);
        $this->customerAddressId = (string) $address->id;
        $this->showAddressCreate = false;
        $this->reset('newAddressAreaId', 'newAddressLine');
        unset($this->addresses);
    }

    public function closeWizardModal(string $modal): void
    {
        Gate::authorize('orders.create');
        abort_unless(in_array($modal, ['showShopCreate', 'showProductCreate', 'showAddressCreate', 'showPhoneCreate'], true), 404);
        $this->{$modal} = false;
    }
}
