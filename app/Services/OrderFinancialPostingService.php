<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialLedgerException;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialOwner;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\OrderDeliveryPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderFinancialPostingService
{
    public const INVOICE_SOURCE = 'CUSTOMER_INVOICE';

    public const COLLECTION_SOURCE = 'INVOICE_COLLECTION';

    public function __construct(
        private readonly FinancialAccountService $accounts,
        private readonly FinancialPostingService $posting,
    ) {}

    /** @return Collection<int, FinancialTransaction> */
    public function postOrderDerivedIfReady(Order $order): Collection
    {
        throw new FinancialLedgerException('Legacy Phase 9B Order events cannot be posted. Use the corrected collection-based posting service.');
    }

    public function postCashCollectionCustody(InvoiceCollection $collection): ?FinancialTransaction
    {
        throw new FinancialLedgerException('Legacy full-Invoice CASH custody cannot be posted. Use the corrected collection-based posting service.');
    }

    public function hasOrderDerivedEvents(Invoice $invoice): bool
    {
        return FinancialTransaction::query()
            ->where('source_type', self::INVOICE_SOURCE)
            ->where('source_id', (string) $invoice->id)
            ->whereIn('event_type', collect(FinancialEventType::orderDerived())->map->value)
            ->exists()
            || FinancialTransaction::query()
                ->where('source_type', self::COLLECTION_SOURCE)
                ->whereIn('source_id', $invoice->collection()->pluck('id')->map(fn ($id) => (string) $id))
                ->whereIn('event_type', [FinancialEventType::SharedInvoiceCashCustodyReceived->value, FinancialEventType::SharedOrderProductReimbursementDue->value, FinancialEventType::SharedOrderDeliveryEntitlementDue->value, ...collect(FinancialEventType::correctedOrderDerived())->map->value])
                ->exists();
    }

    public function assessOrder(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->operational_status !== OrderOperationalStatus::Delivered) {
                return ['state' => 'not_ready', 'reason' => 'Order is not DELIVERED.'];
            }
            try {
                $invoice = $this->currentIssuedInvoice($order);
                [$currency, $allocations, $occurredAt] = $this->authoritativeOrderSources($order, $invoice);
                $concepts = [];
                foreach ($this->expectedOrderConcepts($invoice, $currency, $allocations) as $eventType => $descriptors) {
                    $concepts[$eventType] = $this->inspectConcept(
                        $currency, self::INVOICE_SOURCE, $invoice->id, $eventType, $invoice->version,
                        $occurredAt, $descriptors, $order, $invoice->issuer,
                    );
                }
            } catch (FinancialLedgerException $exception) {
                return ['state' => 'unresolved', 'reason' => $exception->getMessage()];
            }
            $states = collect($concepts)->pluck('state');
            $state = $states->contains('conflict') ? 'conflict' : ($states->contains('missing') ? 'eligible' : 'complete');

            return [
                'state' => $state,
                'invoice_id' => $invoice->id,
                'concepts' => $concepts,
                'missing' => collect($concepts)->where('state', 'missing')->keys()->values()->all(),
                'conflicts' => collect($concepts)->where('state', 'conflict')->keys()->values()->all(),
                'no_effect' => collect($concepts)->where('state', 'no_effect')->keys()->values()->all(),
            ];
        });
    }

    public function assessCashCollection(InvoiceCollection $collection): array
    {
        return DB::transaction(function () use ($collection): array {
            try {
                $collection = InvoiceCollection::query()->findOrFail($collection->id);
                if ($collection->collection_method === InvoiceCollectionMethod::ShamCash) {
                    return ['state' => 'no_effect'];
                }
                [$order, $invoice, $collection, $currency, $assignment, $descriptors] = $this->authoritativeCashSource($collection);
                $result = $this->inspectConcept(
                    $currency, self::COLLECTION_SOURCE, $collection->id,
                    FinancialEventType::InvoiceCashCustodyReceived->value, 1,
                    $collection->collected_at, $descriptors, $order, $collection->recordedBy,
                );

                return $result + ['invoice_id' => $invoice->id, 'order_captain_id' => $assignment->id];
            } catch (FinancialLedgerException $exception) {
                return ['state' => 'unresolved', 'reason' => $exception->getMessage()];
            }
        });
    }

    private function currentIssuedInvoice(Order $order): Invoice
    {
        $invoice = Invoice::query()->where('order_id', $order->id)->whereNull('superseded_at')->lockForUpdate()->first();
        if (! $invoice || $invoice->status !== InvoiceStatus::Issued) {
            throw new FinancialLedgerException('DELIVERED Order has no current valid ISSUED Invoice.');
        }

        return $invoice;
    }

    /** @return array{0: Currency, 1: Collection, 2: CarbonInterface} */
    private function authoritativeOrderSources(Order $order, Invoice $invoice): array
    {
        if (! $invoice->issued_at || ! $invoice->currency_id || ! $invoice->source_order_delivery_pricing_id || $invoice->items_subtotal === null) {
            throw new FinancialLedgerException("Invoice {$invoice->id} lacks a trustworthy issued financial snapshot.");
        }
        $deliveredAt = $order->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->orderByDesc('changed_at')->value('changed_at');
        if (! $deliveredAt) {
            throw new FinancialLedgerException("Delivered Order {$order->id} lacks a trustworthy DELIVERED timestamp.");
        }
        $pricing = OrderDeliveryPricing::query()->lockForUpdate()->find($invoice->source_order_delivery_pricing_id);
        if (! $pricing || $pricing->order_id !== $order->id || $pricing->currency_id !== $invoice->currency_id) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has an inconsistent delivery-pricing snapshot.");
        }
        $allocations = $pricing->allocations()->with(['orderCaptain.captain'])->orderBy('order_captain_id')->lockForUpdate()->get();
        if ($allocations->isEmpty()) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has no historical delivery allocations.");
        }
        if ($allocations->pluck('captain_id')->duplicates()->isNotEmpty()) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has duplicate Captain allocation identities.");
        }
        foreach ($allocations as $allocation) {
            if ($allocation->currency_id !== $invoice->currency_id || ! $allocation->orderCaptain || ! $allocation->orderCaptain->captain
                || $allocation->orderCaptain->order_id !== $order->id || $allocation->orderCaptain->captain_id !== $allocation->captain_id) {
                throw new FinancialLedgerException("Invoice {$invoice->id} has malformed historical Captain allocations.");
            }
        }
        $currency = Currency::query()->lockForUpdate()->findOrFail($invoice->currency_id);
        $deliveredAt = CarbonImmutable::parse($deliveredAt);

        return [$currency, $allocations, $invoice->issued_at->greaterThan($deliveredAt) ? $invoice->issued_at : $deliveredAt];
    }

    private function authoritativeCashSource(InvoiceCollection $source): array
    {
        $order = Order::query()->lockForUpdate()->find($source->order_id);
        $invoice = Invoice::query()->lockForUpdate()->find($source->invoice_id);
        $collection = InvoiceCollection::query()->lockForUpdate()->find($source->id);
        if (! $order || ! $invoice || ! $collection) {
            throw new FinancialLedgerException("Collection {$source->id} has missing authoritative source rows.");
        }
        if ($collection->collection_method !== InvoiceCollectionMethod::Cash) {
            throw new FinancialLedgerException("Collection {$collection->id} has no trustworthy CASH collection method.");
        }
        if ($collection->invoice_id !== $invoice->id || $collection->order_id !== $order->id || $invoice->order_id !== $order->id
            || $invoice->status !== InvoiceStatus::Issued || $invoice->currency_id !== $collection->currency_id
            || ! BigDecimal::of($collection->amount)->isEqualTo(BigDecimal::of($invoice->grand_total)) || ! $collection->collected_at) {
            throw new FinancialLedgerException("Collection {$collection->id} has inconsistent Invoice, amount, Currency, or collection-time data.");
        }
        $assignment = OrderCaptain::query()->with('captain')->lockForUpdate()->find($collection->order_captain_id);
        if (! $assignment || ! $assignment->captain || $assignment->order_id !== $order->id
            || $assignment->captain_id !== $collection->captain_id || $assignment->assignment_role !== OrderCaptainRole::PrimaryCollection) {
            throw new FinancialLedgerException("Collection {$collection->id} has an inconsistent collecting Captain snapshot.");
        }
        $currency = Currency::query()->lockForUpdate()->findOrFail($collection->currency_id);
        $descriptors = [$this->descriptor($assignment->captain, FinancialAccountKind::CaptainCashCustody, FinancialEntryDirection::Increase, $collection->amount)];

        return [$order, $invoice, $collection, $currency, $assignment, $descriptors];
    }

    private function expectedOrderConcepts(Invoice $invoice, Currency $currency, Collection $allocations): array
    {
        $product = [];
        foreach ($this->splitProductSubtotal($invoice->items_subtotal, $currency, $allocations) as $orderCaptainId => $amount) {
            if (! BigDecimal::of($amount)->isZero()) {
                $allocation = $allocations->firstWhere('order_captain_id', $orderCaptainId);
                $product[] = $this->descriptor($allocation->orderCaptain->captain, FinancialAccountKind::CaptainCompanyReceivable, FinancialEntryDirection::Increase, $amount);
            }
        }
        $entitlement = [];
        $companyShare = [];
        foreach ($allocations as $allocation) {
            if ($this->hasFinancialEffect($allocation->captain_entitlement)) {
                $entitlement[] = $this->descriptor($allocation->orderCaptain->captain, FinancialAccountKind::CaptainCompanyReceivable, FinancialEntryDirection::Increase, $allocation->captain_entitlement);
            }
            if ($this->hasFinancialEffect($allocation->company_collectible)) {
                $companyShare[] = $this->descriptor($allocation->orderCaptain->captain, FinancialAccountKind::CaptainDeposit, FinancialEntryDirection::Decrease, $allocation->company_collectible);
            }
        }

        return [
            FinancialEventType::OrderProductReimbursement->value => $product,
            FinancialEventType::OrderDeliveryEntitlement->value => $entitlement,
            FinancialEventType::OrderCompanyDeliveryShare->value => $companyShare,
        ];
    }

    private function inspectConcept(Currency $currency, string $sourceType, int $sourceId, string $eventType, int $sourceVersion, CarbonInterface $occurredAt, array $descriptors, Order $order, mixed $recordedBy): array
    {
        foreach ($descriptors as $descriptor) {
            if (! $descriptor['captain']->exists) {
                throw new FinancialLedgerException('A planned financial movement has no valid Captain owner.');
            }
            $this->posting->validatePlannedAmount($currency, $descriptor['amount']);
        }
        $existing = FinancialTransaction::query()->where('source_type', $sourceType)->where('source_id', (string) $sourceId)->where('event_type', $eventType)->get();
        if ($descriptors === []) {
            return ['state' => $existing->isEmpty() ? 'no_effect' : 'conflict'];
        }
        $movements = $this->materializeMovements($descriptors, $currency, false);
        if ($movements === null) {
            return ['state' => $existing->isEmpty() ? 'missing' : 'conflict'];
        }

        return $this->posting->inspectEvent($currency, $sourceType, $sourceId, $eventType, $sourceVersion, $occurredAt, $movements, $order->branch, $recordedBy);
    }

    private function materializeMovements(array $descriptors, Currency $currency, bool $create): ?array
    {
        $movements = [];
        foreach ($descriptors as $descriptor) {
            $account = $this->captainAccount($descriptor['captain'], $currency, $descriptor['kind'], $create);
            if (! $account) {
                return null;
            }
            $movements[] = ['account' => $account, 'direction' => $descriptor['direction'], 'amount' => $descriptor['amount']];
        }

        return $movements;
    }

    private function captainAccount(Captain $captain, Currency $currency, FinancialAccountKind $kind, bool $create): ?FinancialAccount
    {
        if ($create) {
            return $this->accounts->getOrCreate($this->accounts->ownerForCaptain($captain), $currency, $kind);
        }
        $owner = FinancialOwner::query()->where('captain_id', $captain->id)->first();

        return $owner ? FinancialAccount::query()->where('financial_owner_id', $owner->id)->where('currency_id', $currency->id)->where('account_kind', $kind->value)->first() : null;
    }

    private function splitProductSubtotal(string $subtotal, Currency $currency, Collection $allocations): array
    {
        try {
            $minorTotal = BigDecimal::of($subtotal)->toScale($currency->decimal_places, RoundingMode::Unnecessary)->getUnscaledValue();
        } catch (\Throwable) {
            throw new FinancialLedgerException('Invoice product subtotal is invalid for its Currency precision.');
        }
        [$base, $remainder] = $minorTotal->quotientAndRemainder($allocations->count());
        $remainderCount = $remainder->toInt();
        $shares = [];
        foreach ($allocations->values() as $index => $allocation) {
            $minor = $base->plus($index < $remainderCount ? 1 : 0);
            $shares[$allocation->order_captain_id] = (string) BigDecimal::ofUnscaledValue($minor, $currency->decimal_places)->toScale(4);
        }

        return $shares;
    }

    private function descriptor(Captain $captain, FinancialAccountKind $kind, FinancialEntryDirection $direction, string $amount): array
    {
        return compact('captain', 'kind', 'direction', 'amount');
    }

    private function hasFinancialEffect(string $amount): bool
    {
        try {
            return ! BigDecimal::of($amount)->isZero();
        } catch (\Throwable) {
            throw new FinancialLedgerException('A historical delivery allocation has an invalid financial amount.');
        }
    }
}
