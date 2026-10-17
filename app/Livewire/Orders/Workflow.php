<?php

namespace App\Livewire\Orders;

use App\Enums\InvoiceCollectionMethod;
use App\Http\Requests\InvoiceCollectionRequest;
use App\Models\Order;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\MixedCustomerCreditService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderStatusService;
use App\Services\OrderWorkflowData;
use App\Services\OrderWorkflowState;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Workflow extends Component
{
    use MapsDomainErrors;

    #[Locked]
    public Order $order;

    #[Locked]
    public ?string $panel = null;

    public string $collectionMethod = 'CASH';

    public string $collectedAt = '';

    public string $paymentReference = '';

    public string $collectionNotes = '';

    public array $creditAllocations = [];

    public array $creditAttributions = [];

    public string $collectionActualAmount = '';

    #[Locked]
    public string $creditSettlementKey = '';

    #[Locked]
    public ?string $actionStatus = null;

    public function mount(Order $order): void
    {
        $this->order = $order;
        $this->freshOrder();
        if (request()->filled('step')) {
            $this->openStep((string) request()->query('step'));
        }
        $this->collectedAt = now()->format('Y-m-d\TH:i:s');
        $this->creditSettlementKey = (string) str()->uuid();
    }

    private function freshOrder(): Order
    {
        $this->order = $this->order->fresh();
        app(OrderService::class)->assertAccess(auth()->user(), $this->order);
        Gate::authorize('orders.view');

        return $this->order;
    }

    public function openStep(string $step): void
    {
        $state = app(OrderWorkflowState::class)->describe(auth()->user(), $this->freshOrder());
        abort_unless(array_key_exists($step, $state['steps']) || ($step === 'exception' && $state['exceptional']), 422);
        // Opening a panel never changes persisted facts or authorizes its actions.
        $this->panel = $step;
        $this->resetValidation();
    }

    #[On('order-updated')]
    public function refreshOrder(): void
    {
        $this->freshOrder();
    }

    public function advance(OrderStatusService $statuses): void
    {
        $order = $this->freshOrder();
        $next = $order->operational_status->next();
        abort_unless($next, 422);
        $this->domainAction(fn () => $statuses->transition(auth()->user(), $order, $next, null));
        $this->actionStatus = __('orders.flash.status_updated');
        $this->changed();
    }

    public function pickup(int $shopId, OrderShopProgressService $progress): void
    {
        $order = $this->freshOrder();
        $shop = $order->shops()->findOrFail($shopId);
        $this->domainAction(fn () => $progress->record(auth()->user(), $order, $shop, 'picked_up_at'));
        $this->changed(false);
    }

    public function createInvoice(InvoiceService $invoices): void
    {
        $order = $this->freshOrder();
        $this->domainAction(fn () => $invoices->createDraft(auth()->user(), $order));
        $this->changed(false);
    }

    public function refreshInvoice(InvoiceService $invoices): void
    {
        $order = $this->freshOrder();
        $invoice = $order->currentInvoice()->firstOrFail();
        $this->domainAction(fn () => $invoices->refresh(auth()->user(), $order, $invoice));
        $this->changed(false);
    }

    public function issueInvoice(InvoiceService $invoices): void
    {
        $order = $this->freshOrder();
        $invoice = $order->currentInvoice()->firstOrFail();
        $this->domainAction(fn () => $invoices->issue(auth()->user(), $order, $invoice));
        $this->actionStatus = __('financial.flash.invoice_issued');
        $this->collectedAt = now()->format('Y-m-d\TH:i:s');
        $this->changed(false);
    }

    public function collectInvoice(InvoiceCollectionService $collections): void
    {
        $order = $this->freshOrder();
        Gate::authorize('invoice_collections.create');
        $this->validate([
            'collectionMethod' => (new InvoiceCollectionRequest)->rules()['collection_method'],
            'collectedAt' => (new InvoiceCollectionRequest)->rules()['collected_at'],
            'paymentReference' => (new InvoiceCollectionRequest)->rules()['payment_reference'],
            'collectionNotes' => (new InvoiceCollectionRequest)->rules()['notes'],
            'collectionActualAmount' => ['nullable', 'string', 'regex:/^[0-9]{1,18}(?:\.[0-9]{1,4})?$/D'],
        ], ['collectionActualAmount.regex' => __('credit.settlement_invalid')]);
        $this->domainAction(fn () => DB::transaction(function () use ($collections, $order): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($problem = app(InvoiceService::class)->mutationProblem($order)) {
                throw ValidationException::withMessages(['order' => $problem]);
            }
            $invoice = $order->currentInvoice()->firstOrFail();
            if (collect($this->creditAllocations)->contains(fn ($amount) => $amount !== '' && $amount !== null && (! is_string($amount) || ! preg_match('/^0(?:\.0+)?$/D', $amount)))) {
                app(MixedCustomerCreditService::class)->record(auth()->user(), $invoice, InvoiceCollectionMethod::from($this->collectionMethod), [
                    'request_key' => $this->creditSettlementKey, 'allocations' => $this->creditAllocations,
                    'attributions' => $this->creditAttributions,
                    'actual_amount' => $this->collectionActualAmount, 'collected_at' => $this->collectedAt,
                    'notes' => $this->collectionNotes, 'payment_reference' => $this->paymentReference,
                ]);

                return;
            }
            $collections->record(auth()->user(), $invoice, InvoiceCollectionMethod::from($this->collectionMethod),
                filled($this->collectionActualAmount) ? $this->collectionActualAmount : $invoice->grand_total, $invoice->currency_id, $this->collectedAt, $this->collectionNotes ?: null, $this->paymentReference ?: null);
        }), ['actual_amount' => 'collectionActualAmount', 'allocations' => 'creditAllocations']);
        $this->actionStatus = __('financial.flash.collection_recorded');
        $this->changed();
    }

    private function changed(bool $follow = true): void
    {
        $this->resetValidation();
        if ($follow) {
            $this->panel = null;
        }
        $this->freshOrder();
        $this->dispatch('order-updated');
    }

    public function render()
    {
        $data = app(OrderWorkflowData::class)->load(auth()->user(), $this->freshOrder());
        $workflow = app(OrderWorkflowState::class)->describe(auth()->user(), $this->order);
        // Active cancellation takes precedence even over a previously selected normal panel.
        $activePanel = $workflow['exceptional'] ? 'exception' : ($this->panel ?? $workflow['step']);
        $creditGrants = collect();
        $creditTotal = BigDecimal::zero();
        $creditExpectedActual = null;
        if ($workflow['canCollect'] && auth()->user()->can('customer_credits.view') && auth()->user()->can('customer_credits.redeem')
            && auth()->user()->can('customers.view') && in_array($this->order->accounting_contract_version, [2, 3], true)
            && auth()->user()->canAccessCustomer($this->order->customer)
            && Schema::hasTable('customer_credit_settlements')) {
            $creditGrants = app(MixedCustomerCreditService::class)->grants(auth()->user(), $this->order, $this->order->currentInvoice->currency_id);
            try {
                foreach ($this->creditAllocations as $amount) {
                    if ($amount !== '' && $amount !== null) {
                        if (! is_string($amount) || ! preg_match('/^\d+(?:\.\d+)?$/D', $amount)) {
                            throw new \InvalidArgumentException;
                        }
                        $creditTotal = $creditTotal->plus($amount);
                    }
                }
                $creditExpectedActual = (string) BigDecimal::of($this->order->currentInvoice->grand_total)->minus($creditTotal)->toScale(4);
            } catch (\Throwable) {
                $creditExpectedActual = null;
            }
        }

        return view('livewire.orders.workflow', $data + compact('workflow', 'activePanel', 'creditGrants', 'creditTotal', 'creditExpectedActual'));
    }
}
