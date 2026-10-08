<?php

namespace App\Livewire\Orders;

use App\Enums\OrderEntryMode;
use App\Enums\OrderSource;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Models\Order;
use App\Rules\MobilePhone;
use App\Services\CustomerAddressService;
use App\Services\CustomerPhoneService;
use App\Services\CustomerService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use App\Services\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateOrder extends Component
{
    use CreatesOrderWizard;
    use MapsDomainErrors;
    use OverridesWizardDelivery;
    use SelectsSoleSearchMatch;

    public string $branchId = '';

    public string $customerId = '';

    public string $customerPhoneId = '';

    public string $customerAddressId = '';

    public string $source = '';

    public string $entryMode = 'LIVE';

    public string $orderedAt = '';

    public string $notes = '';

    public string $customerSearch = '';

    public bool $showCustomerCreate = false;

    public string $newCustomerName = '';

    public string $newCustomerPhone = '';

    public string $newCustomerAddress = '';

    public string $newCustomerAreaId = '';

    public bool $newCustomerDuplicateAcknowledged = false;

    private bool $branchChanged = false;

    public function mount(): void
    {
        Gate::authorize('orders.create');
        $this->submissionKey = (string) Str::uuid();
        $branches = $this->branches;
        if ($branches->count() === 1) {
            $this->branchId = (string) $branches->sole()->id;
            $this->selectSingleNewCustomerArea();
        }
    }

    #[Computed]
    public function branches(): Collection
    {
        return auth()->user()->availableBranches()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function customers(): Collection
    {
        return $this->customerQuery(false)->get();
    }

    #[Computed]
    public function selectedCustomer(): ?Customer
    {
        return $this->customerId === '' ? null : Customer::query()->accessibleTo(auth()->user())
            ->where('is_active', true)->find($this->customerId);
    }

    #[Computed]
    public function customerMatches(): Collection
    {
        return $this->customerQuery(false)->get();
    }

    public function searchCustomers(string $search): array
    {
        Gate::authorize('orders.create');
        $this->skipRender();

        return $this->customerQuery(false, $search)->get()->map(function (Customer $customer): array {
            $label = $customer->name;
            if ($phone = $customer->phones->sortByDesc('is_primary')->first()) {
                $label .= ' — '."\u{2066}".$phone->phone."\u{2069}";
            }
            if ($address = $customer->addresses->first()) {
                $label .= ' — '.$address->area->name.' · '.$address->address_line_1;
            }

            return ['id' => (string) $customer->id, 'text' => $label];
        })->all();
    }

    private function customerQuery(bool $includeSelected, ?string $search = null): Builder
    {
        $search = trim($search ?? $this->customerSearch);

        return Customer::query()->accessibleTo(auth()->user())->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->search($search, app(PhoneNormalizer::class))
                ->when($includeSelected && $this->customerId !== '', fn (Builder $selected) => $selected->orWhereKey($this->customerId))))
            ->with(['phones', 'addresses' => fn ($addresses) => $addresses->where('is_active', true)
                ->whereHas('area', fn (Builder $areas) => $areas->accessibleTo(auth()->user())
                    ->when($this->branchId !== '', fn (Builder $branches) => $branches->where('branch_id', $this->branchId)))
                ->with('area')->orderByDesc('is_primary')->orderBy('id')->limit(1)])
            ->orderBy('name')->limit(50);
    }

    #[Computed]
    public function phones(): Collection
    {
        if (! $this->customerId) {
            return collect();
        }

        return Customer::query()->accessibleTo(auth()->user())->whereKey($this->customerId)->first()?->phones()
            ->orderByDesc('is_primary')->orderBy('id')->get() ?? collect();
    }

    #[Computed]
    public function addresses(): Collection
    {
        if (! $this->customerId || ! $this->branchId) {
            return collect();
        }

        return Customer::query()->accessibleTo(auth()->user())->whereKey($this->customerId)->first()?->addresses()
            ->where('is_active', true)
            ->whereHas('area', fn (Builder $areas) => $areas->where('is_active', true)->where('branch_id', $this->branchId))
            ->with('area.parent')->orderByDesc('is_primary')->orderBy('id')->get() ?? collect();
    }

    #[Computed]
    public function areas(): Collection
    {
        if (! $this->branchId) {
            return collect();
        }

        return Area::query()->accessibleTo(auth()->user())->where('branch_id', $this->branchId)
            ->where('is_active', true)->with('parent')->orderBy('name')->get();
    }

    public function updatedCustomerId(): void
    {
        unset($this->phones, $this->addresses);
        // Update hooks run after the full Livewire payload has been applied.
        if (! $this->phones->contains('id', $this->customerPhoneId)) {
            $this->customerPhoneId = '';
        }
        if (! $this->addresses->contains('id', $this->customerAddressId)) {
            $this->customerAddressId = '';
        }
        $this->resetValidation(['customerPhoneId', 'customerAddressId']);
        $this->validateOnly('customerId');
        $this->selectSingleContactOptions();
    }

    public function updatingBranchId(string $value): void
    {
        $this->branchChanged = $value !== $this->branchId;
        if ($this->branchChanged) {
            $this->step = 1;
        }
    }

    public function updatedBranchId(): void
    {
        // Livewire runs hooks even for an unchanged value in a bundled update.
        if (! $this->branchChanged) {
            return;
        }
        unset($this->overrideCaptains);
        unset($this->addresses, $this->areas);
        if (! $this->addresses->contains('id', $this->customerAddressId)) {
            $this->customerAddressId = '';
        }
        $this->resetValidation('customerAddressId');
        $this->validateOnly('branchId');
        $this->selectSingleAddress();
        $this->selectSingleNewCustomerArea();
    }

    public function updatedCustomerSearch(): void
    {
        unset($this->customers, $this->customerMatches);
    }

    public function selectSoleCustomer(string $query): void
    {
        Gate::authorize('orders.create');
        $this->selectSoleSearchMatch($query, 'customerSearch', 'customerMatches', $this->selectCustomer(...));
    }

    public function selectCustomer(int $customerId): void
    {
        Gate::authorize('orders.create');
        $customer = Customer::query()->accessibleTo(auth()->user())->where('is_active', true)->findOrFail($customerId);
        $changed = $this->customerId !== (string) $customer->id;
        $this->customerId = (string) $customer->id;
        $this->customerSearch = $customer->name;
        unset($this->customers, $this->customerMatches);
        if ($changed) {
            $this->updatedCustomerId();
        }
    }

    public function toggleCustomerCreate(): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('customers.create');
        $this->showCustomerCreate = ! $this->showCustomerCreate;
        if ($this->showCustomerCreate) {
            $this->selectSingleNewCustomerArea();
        }
    }

    public function closeCustomerCreate(): void
    {
        $this->showCustomerCreate = false;
        $this->resetValidation(['newCustomerName', 'newCustomerPhone', 'newCustomerAddress', 'newCustomerAreaId', 'newCustomerDuplicateAcknowledged']);
    }

    public function createCustomer(CustomerService $customers, CustomerPhoneService $phones, CustomerAddressService $addresses): void
    {
        Gate::authorize('orders.create');
        Gate::authorize('customers.create');
        $data = $this->validate([
            'branchId' => ['required', 'integer', 'exists:branches,id'],
            'newCustomerName' => ['required', 'string', 'max:255'],
            'newCustomerPhone' => ['required', 'string', new MobilePhone(true)],
            'newCustomerAddress' => ['required', 'string', 'max:500'],
            'newCustomerAreaId' => ['required', 'integer', 'exists:areas,id'],
            'newCustomerDuplicateAcknowledged' => ['boolean'],
        ]);
        Area::query()->accessibleTo(auth()->user())->where('branch_id', $data['branchId'])
            ->where('is_active', true)->findOrFail($data['newCustomerAreaId']);

        [$customer, $phone, $address] = DB::transaction(function () use ($customers, $phones, $addresses, $data): array {
            $customer = $this->domainAction(fn () => $customers->save(auth()->user(), new Customer, [
                'name' => $data['newCustomerName'],
            ]), ['name' => 'newCustomerName']);
            $phone = $this->domainAction(fn () => $phones->save(auth()->user(), $customer, new CustomerPhone, [
                'phone' => $data['newCustomerPhone'], 'is_primary' => true,
                'duplicate_acknowledged' => $data['newCustomerDuplicateAcknowledged'],
            ]), ['phone' => 'newCustomerPhone']);
            $address = $this->domainAction(fn () => $addresses->save(auth()->user(), $customer, new CustomerAddress, [
                'area_id' => $data['newCustomerAreaId'], 'address_line_1' => $data['newCustomerAddress'], 'is_primary' => true,
            ]), ['area_id' => 'newCustomerAreaId', 'address_line_1' => 'newCustomerAddress']);

            return [$customer, $phone, $address];
        });

        $this->customerId = (string) $customer->id;
        $this->customerPhoneId = (string) $phone->id;
        $this->customerAddressId = (string) $address->id;
        $this->customerSearch = $customer->name;
        $this->showCustomerCreate = false;
        $this->reset('newCustomerName', 'newCustomerPhone', 'newCustomerAddress', 'newCustomerAreaId', 'newCustomerDuplicateAcknowledged');
        unset($this->customers, $this->phones, $this->addresses);
        session()->flash('order-create-status', __('orders.flash.customer_created'));
    }

    public function updatedEntryMode(string $value): void
    {
        if ($value !== OrderEntryMode::HistoricalManual->value) {
            $this->orderedAt = '';
            $this->resetValidation('orderedAt');
        }
        $this->validateOnly('entryMode');
    }

    public function updatedCustomerPhoneId(): void
    {
        $this->validateOnly('customerPhoneId');
    }

    public function updatedCustomerAddressId(): void
    {
        $this->validateOnly('customerAddressId');
    }

    public function updatedSource(): void
    {
        $this->validateOnly('source');
    }

    public function updatedOrderedAt(): void
    {
        $this->validateOnly('orderedAt');
    }

    public function submitWizard(OrderService $orders): void
    {
        if ($this->createdOrderId || $this->step === 4) {
            $this->submit($orders);
        } else {
            $this->nextStep();
        }
    }

    public function submit(OrderService $orders): void
    {
        Gate::authorize('orders.create');
        if ($this->createdOrderId) {
            $order = Order::query()->accessibleTo(auth()->user())->findOrFail($this->createdOrderId);
            $this->redirectRoute('orders.workflow', $order);

            return;
        }
        $key = 'order-wizard:'.auth()->id().':'.$this->submissionKey;
        $lock = Cache::lock($key.':lock', 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['wizard' => __('orders.wizard.submitting')]);
        }
        $draftValidated = false;
        try {
            $id = Cache::get($key);
            if ($id && Order::query()->accessibleTo(auth()->user())->whereKey($id)->exists()) {
                $this->createdOrderId = $id;
                $this->redirectRoute('orders.workflow', $id);

                return;
            }
            for ($step = 1; $step <= 3; $step++) {
                $this->validateWizardStep($step);
            }
            if ($this->step !== 4) {
                throw ValidationException::withMessages(['wizard' => __('orders.wizard.review_required')]);
            }
            $data = $this->validate();
            $draftValidated = true;
            $order = DB::transaction(function () use ($orders, $data) {
                $order = $this->domainAction(fn () => $orders->create(auth()->user(), [
                    'branch_id' => $data['branchId'], 'customer_id' => $data['customerId'],
                    'customer_phone_id' => $data['customerPhoneId'], 'customer_address_id' => $data['customerAddressId'],
                    'source' => $data['source'], 'entry_mode' => $data['entryMode'],
                    'ordered_at' => $data['orderedAt'] ?: null, 'notes' => $data['notes'] ?: null,
                ]));
                foreach ($this->groups as $group) {
                    $shop = app(OrderShopService::class)->add(auth()->user(), $order, Arr::only($group, [
                        'shop_type', 'shop_id', 'shop_name', 'shop_phone', 'shop_address', 'origin_area_id', 'business_classification',
                    ]));
                    foreach ($group['items'] as $item) {
                        app(OrderItemService::class)->add(auth()->user(), $order, $shop, Arr::only($item, [
                            'item_type', 'product_id', 'item_name', 'unit_id', 'quantity',
                        ]));
                    }
                }
                $proposal = app(OrderDeliveryPricingService::class)->preview(auth()->user(), $order);
                if (! $proposal['complete']) {
                    throw ValidationException::withMessages(['delivery' => __('orders.wizard.missing_route')]);
                }

                $this->persistWizardOverride($order, $proposal);

                return $order;
            });
            $this->createdOrderId = $order->id;
            Cache::put($key, $order->id, now()->addDay());
        } catch (ValidationException $exception) {
            Cache::forget($key);
            $fields = array_keys($exception->errors());
            if ($draftValidated) {
                $this->step = array_intersect($fields, ['branchId', 'customerId', 'customerPhoneId', 'customerAddressId', 'source', 'entryMode', 'orderedAt']) ? 1
                    : (array_intersect($fields, ['delivery', 'deliveryOverrideAmount', 'deliveryOverrideReason', 'deliveryOverrideCaptainId', 'captainShare', 'captains', 'fromAreaId']) ? 3 : 2);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Cache::forget($key);
            throw $exception;
        } finally {
            $lock->release();
        }

        session()->flash('status', __('orders.workflow.created_reference', ['reference' => $order->order_number]));
        $this->redirectRoute('orders.workflow', $order);
    }

    protected function rules(): array
    {
        return [
            'branchId' => ['required', 'integer', 'exists:branches,id'],
            'customerId' => ['required', 'integer', 'exists:customers,id'],
            'customerPhoneId' => ['required', 'integer', 'exists:customer_phones,id'],
            'customerAddressId' => ['required', 'integer', 'exists:customer_addresses,id'],
            'source' => ['required', Rule::in(array_map(fn (OrderSource $source) => $source->value, OrderSource::staffChoices()))],
            'entryMode' => ['required', Rule::enum(OrderEntryMode::class)],
            'orderedAt' => ['nullable', 'required_if:entryMode,'.OrderEntryMode::HistoricalManual->value, 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'shopType' => __('orders.ui_shop_source'), 'shopId' => __('orders.ui_registered_shop'),
            'shopName' => __('orders.ui_one_shop_name'), 'originAreaId' => __('common.terms.area'),
            'businessClassification' => __('orders.ui_shop_classification'), 'itemType' => __('orders.ui_item_type'),
            'productId' => __('common.terms.product'), 'itemName' => __('orders.ui_one_item_name'),
            'unitId' => __('common.terms.unit'), 'quantity' => __('orders.ui_quantity'),
            'newShopName' => __('common.fields.name'), 'newShopAreaId' => __('common.terms.area'),
            'newShopClassification' => __('orders.ui_shop_classification'), 'newShopTypeId' => __('orders.ui_shop_type'), 'newProductName' => __('common.fields.name'),
            'newProductSku' => __('catalog.sku'), 'newProductUnitId' => __('common.terms.unit'),
            'newProductCategoryId' => __('common.terms.category'), 'newAddressLine' => __('orders.ui_delivery_address'),
            'newAddressAreaId' => __('common.terms.area'),
            'branchId' => __('common.terms.branch'), 'customerId' => __('common.terms.customer'),
            'customerPhoneId' => __('orders.ui_customer_phone'), 'customerAddressId' => __('orders.ui_delivery_address'),
            'source' => __('orders.ui_source_label'), 'entryMode' => __('orders.ui_entry_mode'),
            'orderedAt' => __('orders.ui_historical_time'), 'notes' => __('common.fields.notes'),
            'deliveryOverrideAmount' => __('wizard_delivery.requested'),
            'deliveryOverrideReason' => __('wizard_delivery.reason'),
            'deliveryOverrideCaptainId' => __('wizard_delivery.captain'),
        ];
    }

    public function render()
    {
        Gate::authorize('orders.create');

        return view('livewire.orders.create-order', [
            'sources' => OrderSource::staffChoices(),
            'entryModes' => auth()->user()->can('orders.historical_entry') ? OrderEntryMode::cases() : [OrderEntryMode::Live],
        ]);
    }

    private function selectSingleContactOptions(): void
    {
        if ($this->phones->count() === 1) {
            $this->customerPhoneId = (string) $this->phones->sole()->id;
        }
        $this->selectSingleAddress();
    }

    private function selectSingleAddress(): void
    {
        unset($this->addresses);
        if ($this->addresses->count() === 1) {
            $this->customerAddressId = (string) $this->addresses->sole()->id;
        }
    }

    private function selectSingleNewCustomerArea(): void
    {
        unset($this->areas);
        $this->newCustomerAreaId = $this->areas->count() === 1 ? (string) $this->areas->sole()->id : '';
    }
}
