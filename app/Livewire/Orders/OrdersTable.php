<?php

namespace App\Livewire\Orders;

use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingStatus;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;

class OrdersTable extends PowerGridComponent
{
    private const SORT_COLUMNS = ['order_number', 'ordered_at', 'customer_name_snapshot', 'operational_status'];

    public string $tableName = 'ordersIndex';

    #[Url(history: true, except: '')]
    public string $search = '';

    #[Url(as: 'branch_id', history: true, except: '')]
    public string $branchId = '';

    #[Url(as: 'operational_status', history: true, except: '')]
    public string $status = '';

    #[Url(as: 'pricing_status', history: true, except: '')]
    public string $pricingStatus = '';

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    #[Url(as: 'sort', history: true, except: 'ordered_at')]
    public string $sortField = 'ordered_at';

    #[Url(as: 'direction', history: true, except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', history: true, except: 25)]
    public int $perPage = 25;

    public function boot(): void
    {
        Gate::authorize('orders.view');
    }

    public function mount(): void
    {
        // Use PowerGrid's query engine without its optional JavaScript row/action templates.
        $this->showCheckBox();
        $this->normalizeOptions();
    }

    public function setUp(): array
    {
        return [PowerGrid::footer()->showPerPage($this->perPage, [10, 25, 50])->showRecordCount()];
    }

    public function columns(): array
    {
        return [
            Column::make(__('orders.table.reference'), 'order_number')->searchable()->sortable(),
            Column::make(__('common.terms.customer'), 'customer_name_snapshot')->searchable()->sortable(),
            Column::make(__('common.fields.phone'), 'customer_phone_snapshot')->searchable(),
            Column::make(__('orders.table.status'), 'operational_status')->sortable(),
            Column::make(__('orders.table.date'), 'ordered_at')->sortable(),
        ];
    }

    public function beforeSearch(string $field, ?string $search): string
    {
        // PowerGrid HTML-escapes search text; SQL bindings need the original name/phone characters.
        return trim($this->search);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'branchId', 'status', 'pricingStatus', 'from', 'to', 'sortField', 'sortDirection', 'perPage'], true)) {
            $this->resetValidation();
            $this->resetPage();
        }
    }

    public function updatedSortDirection(): void
    {
        if (! in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = 'desc';
        }
    }

    public function sortBy(string $field, string $direction = 'asc'): void
    {
        if (! in_array($field, self::SORT_COLUMNS, true)) {
            throw ValidationException::withMessages(['sortField' => __('orders.table.invalid_sort')]);
        }
        parent::sortBy($field, $direction);
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'branchId', 'status', 'pricingStatus', 'from', 'to', 'sortField', 'sortDirection');
        $this->resetValidation();
        $this->resetPage();
    }

    public function datasource(): Builder
    {
        // Guard native PowerGrid data paths too, not just the visible table render.
        Gate::authorize('orders.view');
        $this->normalizeOptions();
        $query = Order::query()->accessibleTo(auth()->user())
            ->select(['orders.id', 'order_number', 'customer_name_snapshot', 'customer_phone_snapshot', 'branch_id', 'operational_status', 'pricing_status', 'ordered_at'])
            ->with('branch:id,name');
        $validation = Validator::make([
            'branchId' => $this->branchId ?: null, 'status' => $this->status ?: null,
            'pricingStatus' => $this->pricingStatus ?: null, 'from' => $this->from ?: null, 'to' => $this->to ?: null,
        ], [
            'branchId' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'status' => ['nullable', Rule::enum(OrderOperationalStatus::class)],
            'pricingStatus' => ['nullable', Rule::enum(OrderPricingStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])],
        ], [], [
            'branchId' => __('common.terms.branch'), 'status' => __('orders.table.status'),
            'pricingStatus' => __('orders.table.pricing'), 'from' => __('orders.table.from'), 'to' => __('orders.table.to'),
        ]);
        if ($validation->fails()) {
            $this->setErrorBag($validation->errors());

            return $query->whereRaw('1 = 0');
        }

        return $query->when($this->branchId !== '', fn (Builder $orders) => $orders->where('branch_id', $this->branchId))
            ->when($this->status !== '', fn (Builder $orders) => $orders->where('operational_status', $this->status))
            ->when($this->pricingStatus !== '', fn (Builder $orders) => $orders->where('pricing_status', $this->pricingStatus))
            ->when($this->from !== '', fn (Builder $orders) => $orders->whereDate('ordered_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $orders) => $orders->whereDate('ordered_at', '<=', $this->to));
    }

    public function transformQuery(mixed $query): mixed
    {
        // A stable tie-break keeps equal dates/names from drifting between pages.
        return $query->orderBy('orders.id', $this->sortDirection);
    }

    public function render(): View
    {
        Gate::authorize('orders.view');
        $this->normalizeOptions();
        $page = $this->paginators['page'] ?? 1;
        if (filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, $this->perPage)]]) === false) {
            $this->resetPage();
        }
        // PowerGrid owns SQL search/sort/pagination; Abshir owns the Arabic mobile presentation.
        $orders = $this->records;
        if ($orders->currentPage() > $orders->lastPage()) {
            $this->setPage($orders->lastPage());
            unset($this->records);
            $orders = $this->records;
        }
        // Selection is presentation-only and restricted to the currently visible, authorized page.
        $visibleIds = $orders->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->checkboxValues = array_values(array_intersect($visibleIds, $this->checkboxValues));
        $this->checkboxAll = $visibleIds !== [] && count($this->checkboxValues) === count($visibleIds);

        return view('livewire.orders.orders-table', [
            'orders' => $orders,
            'branches' => auth()->user()->availableBranches()->orderBy('name')->get(['id', 'name']),
            'statuses' => OrderOperationalStatus::cases(),
            'pricingStatuses' => OrderPricingStatus::cases(),
            'hasOrders' => $orders->total() > 0 || Order::query()->accessibleTo(auth()->user())->exists(),
        ]);
    }

    private function normalizeOptions(): void
    {
        if (! in_array($this->sortField, self::SORT_COLUMNS, true)) {
            $this->sortField = 'ordered_at';
        }
        if (! in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = 'desc';
        }
        if (! in_array($this->perPage, [10, 25, 50], true)) {
            $this->perPage = 25;
        }
        // Rebuild query configuration on the server instead of trusting browser-hydrated package state.
        $this->columns = $this->columns();
        $this->setUp = ['footer' => $this->setUp()[0]];
        $this->multiSort = false;
        $this->sortArray = [];
        $this->filters = [];
        $this->primaryKey = 'id';
        $this->checkboxAttribute = 'id';
        $this->primaryKeyAlias = null;
        $this->ignoreTablePrefix = true;
        $this->supportModel = true;
        $this->paginateRaw = false;
        $this->softDeletes = '';
        $this->persist = [];
        $this->isExporting = false;
        $this->readyToLoad = true;
    }
}
