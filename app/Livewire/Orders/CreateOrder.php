<?php

namespace App\Livewire\Orders;

use App\Enums\OrderEntryMode;
use App\Enums\OrderSource;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Services\CustomerAddressService;
use App\Services\CustomerPhoneService;
use App\Services\CustomerService;
use App\Services\OrderService;
use App\Services\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateOrder extends Component
{
    use MapsDomainErrors;

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

    public function mount(): void
    {
        Gate::authorize('orders.create');
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
        $search = trim($this->customerSearch);

        return Customer::query()->accessibleTo(auth()->user())->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->search($search, app(PhoneNormalizer::class))
                ->when($this->customerId !== '', fn (Builder $selected) => $selected->orWhereKey($this->customerId))))
            ->with('phones')->orderBy('name')->limit(50)->get();
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
        $this->customerPhoneId = '';
        $this->customerAddressId = '';
        unset($this->phones, $this->addresses);
        $this->resetValidation(['customerPhoneId', 'customerAddressId']);
        $this->validateOnly('customerId');
        $this->selectSingleContactOptions();
    }

    public function updatedBranchId(): void
    {
        $this->customerAddressId = '';
        unset($this->addresses);
        $this->resetValidation('customerAddressId');
        $this->validateOnly('branchId');
        $this->selectSingleAddress();
        $this->selectSingleNewCustomerArea();
    }

    public function updatedCustomerSearch(): void
    {
        unset($this->customers);
    }

    public function selectFirstCustomer(): void
    {
        if ($customer = $this->customers->first()) {
            $this->selectCustomer($customer->id);
        }
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::query()->accessibleTo(auth()->user())->where('is_active', true)->findOrFail($customerId);
        $this->customerId = (string) $customer->id;
        $this->customerSearch = $customer->name;
        unset($this->customers);
        $this->updatedCustomerId();
    }

    public function toggleCustomerCreate(): void
    {
        $this->showCustomerCreate = ! $this->showCustomerCreate;
        if ($this->showCustomerCreate) {
            $this->selectSingleNewCustomerArea();
        }
    }

    public function createCustomer(CustomerService $customers, CustomerPhoneService $phones, CustomerAddressService $addresses): void
    {
        Gate::authorize('customers.create');
        $data = $this->validate([
            'branchId' => ['required', 'integer', 'exists:branches,id'],
            'newCustomerName' => ['required', 'string', 'max:255'],
            'newCustomerPhone' => ['required', 'string', 'max:50'],
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

    public function submit(OrderService $orders): void
    {
        Gate::authorize('orders.create');
        $data = $this->validate();
        $order = $this->domainAction(fn () => $orders->create(auth()->user(), [
            'branch_id' => $data['branchId'],
            'customer_id' => $data['customerId'],
            'customer_phone_id' => $data['customerPhoneId'],
            'customer_address_id' => $data['customerAddressId'],
            'source' => $data['source'],
            'entry_mode' => $data['entryMode'],
            'ordered_at' => $data['orderedAt'] ?: null,
            'notes' => $data['notes'] ?: null,
        ]));

        session()->flash('status', __('orders.flash.created'));
        $this->redirectRoute('orders.show', $order);
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
            'branchId' => __('common.terms.branch'), 'customerId' => __('common.terms.customer'),
            'customerPhoneId' => __('orders.ui_customer_phone'), 'customerAddressId' => __('orders.ui_delivery_address'),
            'source' => __('orders.ui_source_label'), 'entryMode' => __('orders.ui_entry_mode'),
            'orderedAt' => __('orders.ui_historical_time'), 'notes' => __('common.fields.notes'),
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
