<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionState;
use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialOwner;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CorrectedOrderFinancialService
{
    public const SOURCE = 'INVOICE_COLLECTION';

    public function correctionPostingProblem(Order $order): ?string
    {
        return $this->correctionProblem($this->postingCorrection($order));
    }

    private function postingCorrection(Order $order): ?InvoiceFinancialCorrection
    {
        return Schema::hasTable('invoice_financial_corrections')
            ? InvoiceFinancialCorrection::query()->where('order_id', $order->id)
                ->whereIn('state', ['REQUESTED', 'PREPARED', 'READY_TO_FINALIZE', 'COMPLETED'])
                ->orderByDesc('id')->first()
            : null;
    }

    private function correctionProblem(?InvoiceFinancialCorrection $correction): ?string
    {
        return $correction && $correction->state !== InvoiceFinancialCorrectionState::Completed
            ? __('domain.an_unresolved_invoice_financial_correction_blocks_original_order_posting') : null;
    }

    public function __construct(
        private readonly OrderFinancialSourceSnapshotService $snapshots,
        private readonly FinancialPostingService $posting,
        private readonly FinancialAccountService $accounts,
        private readonly InvoiceCorrectionFinancialPostingService $correctionPosting,
    ) {}

    /** Read-only assessment, including strict checks against any posted corrected events. */
    public function assess(Order $order, bool $allowLegacy = false): array
    {
        return DB::transaction(function () use ($order, $allowLegacy): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            return $this->assessLocked($order, $allowLegacy);
        });
    }

    /** @return array<int, FinancialTransaction> */
    public function postIfReady(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $correction = $this->postingCorrection($order);
            if ($correction) {
                if ($problem = $this->correctionProblem($correction)) {
                    throw ValidationException::withMessages(['order' => $problem]);
                }

                return $this->correctionPosting->postIfDeliveredLocked($order, $correction);
            }
            if (Schema::hasTable('order_cancellation_intents') && $order->cancellationIntent()->exists()) {
                throw new FinancialLedgerException('Order financial posting is blocked by an AFTER_PURCHASE cancellation intent.');
            }
            $assessment = $this->assessLocked($order, false);
            if (str_starts_with($assessment['state'], 'waiting_') || $assessment['state'] === 'complete') {
                return [];
            }
            if ($assessment['state'] === 'legacy_correction_required' || $assessment['state'] === 'conflict') {
                throw new FinancialIdempotencyConflictException($assessment['reason']);
            }
            if ($assessment['state'] === 'unresolved') {
                throw new FinancialLedgerException($assessment['reason']);
            }

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($assessment['invoice_id']);
            $collection = InvoiceCollection::query()->lockForUpdate()->findOrFail($assessment['collection_id']);
            $snapshot = $this->snapshots->read($invoice);
            $this->snapshots->capture($invoice);
            $currency = Currency::query()->lockForUpdate()->findOrFail($snapshot['currency_id']);
            $branch = Branch::query()->findOrFail($snapshot['branch_id']);
            $events = [];
            foreach ($this->concepts($snapshot, $collection) as $eventType => $descriptors) {
                if ($descriptors === []) {
                    continue;
                }
                $events[] = $this->posting->postEvent(
                    $currency, self::SOURCE, $collection->id, $eventType, 1,
                    CarbonImmutable::parse($assessment['occurred_at']),
                    $this->movements($descriptors, $currency, true),
                    $branch, $collection->recordedBy, null, $assessment['notes'],
                );
            }

            return $events;
        }, 3);
    }

    private function assessLocked(Order $order, bool $allowLegacy): array
    {
        $invoice = Invoice::query()->where('order_id', $order->id)->whereNull('superseded_at')->lockForUpdate()->first();
        $collection = $invoice?->collection()->lockForUpdate()->first();
        $legacy = $this->unreversedLegacy($order);
        if ($legacy !== [] && ! $allowLegacy) {
            return ['state' => 'legacy_correction_required', 'reason' => 'Unreversed Phase 9B events require the dedicated financial correction command.', 'legacy_ids' => $legacy];
        }
        if (! $invoice || $invoice->status !== InvoiceStatus::Issued) {
            return ['state' => 'waiting_invoice', 'invoice_id' => $invoice?->id];
        }
        if (! $collection) {
            if (InvoiceCollection::query()->where('order_id', $order->id)->exists()) {
                return ['state' => 'conflict', 'reason' => 'Order has a Collection for a non-current Invoice.'];
            }

            return ['state' => 'waiting_collection', 'invoice_id' => $invoice->id];
        }
        try {
            if ($collection->order_id !== $order->id || $collection->invoice_id !== $invoice->id
                || $collection->currency_id !== $invoice->currency_id || ! $collection->collected_at || ! $invoice->issued_at
                || ! BigDecimal::of($collection->amount)->isEqualTo(BigDecimal::of($invoice->grand_total))) {
                throw new FinancialLedgerException('Collection does not match the full current issued Invoice.');
            }
            if (! in_array($collection->collection_method, [InvoiceCollectionMethod::Cash, InvoiceCollectionMethod::ShamCash], true)) {
                throw new FinancialLedgerException('Unsupported Invoice collection method.');
            }
            if ($order->operational_status !== OrderOperationalStatus::Delivered) {
                return ['state' => 'waiting_delivery', 'invoice_id' => $invoice->id, 'collection_id' => $collection->id];
            }
            $snapshot = $this->snapshots->read($invoice);
            $primary = collect($snapshot['captain_allocations'])->firstWhere('is_primary', true);
            if (! $primary) {
                throw new FinancialLedgerException('Historical PRIMARY_COLLECTION assignment is missing.');
            }
            if ($collection->collection_method === InvoiceCollectionMethod::Cash) {
                if ($collection->order_captain_id !== $snapshot['primary_order_captain_id'] || $collection->captain_id !== $primary['captain_id']) {
                    throw new FinancialLedgerException('CASH collector differs from the historical PRIMARY_COLLECTION Captain.');
                }
            } elseif ($collection->collection_method === InvoiceCollectionMethod::ShamCash) {
                if ($collection->captain_id !== null || $collection->order_captain_id !== null) {
                    throw new FinancialLedgerException('SHAM_CASH collection has an unexpected Captain collector.');
                }
            } else {
                throw new FinancialLedgerException('Unsupported Invoice collection method.');
            }
            $delivered = $order->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)
                ->orderByDesc('changed_at')->orderByDesc('id')->first();
            if (! $delivered || ! $delivered->changed_at) {
                throw new FinancialLedgerException('DELIVERED Order lacks authoritative status history.');
            }
            $currency = Currency::query()->findOrFail($snapshot['currency_id']);
            $issuedAt = CarbonImmutable::parse($snapshot['issued_at'])->utc();
            $collectedAt = CarbonImmutable::parse(DB::table('invoice_collections')->where('id', $collection->id)->value('collected_at'))->utc();
            $deliveredAt = CarbonImmutable::parse(DB::table('order_status_history')->where('id', $delivered->id)->value('changed_at'))->utc();
            $occurredAt = collect([$issuedAt, $collectedAt, $deliveredAt])->sort()->last();
            $branch = Branch::query()->findOrFail($snapshot['branch_id']);
            $notes = $this->canonicalJson([
                'order_id' => $order->id,
                'branch_id' => $snapshot['branch_id'],
                'invoice_id' => $invoice->id,
                'invoice_version' => $invoice->version,
                'collection_id' => $collection->id,
                'collection_method' => $collection->collection_method->value,
                'collected_at' => $collectedAt->format('Y-m-d\TH:i:s.uP'),
                'issued_at' => $issuedAt->utc()->format('Y-m-d\TH:i:s.uP'),
                'delivered_history_id' => $delivered->id,
                'delivered_at' => $deliveredAt->format('Y-m-d\TH:i:s.uP'),
                'financial_source' => $snapshot,
                'collector_captain_id' => $collection->captain_id,
            ]);
            $concepts = [];
            foreach ($this->concepts($snapshot, $collection) as $eventType => $descriptors) {
                $existing = FinancialTransaction::query()->where('source_type', self::SOURCE)
                    ->where('source_id', (string) $collection->id)->where('event_type', $eventType)->exists();
                if ($descriptors === []) {
                    $concepts[$eventType] = $existing ? 'conflict' : 'no_effect';

                    continue;
                }
                foreach ($descriptors as $descriptor) {
                    $this->posting->validatePlannedAmount($currency, $descriptor['amount']);
                }
                $movements = $this->movements($descriptors, $currency, false);
                $concepts[$eventType] = $movements === null
                    ? ($existing ? 'conflict' : 'missing')
                    : $this->posting->inspectEvent(
                        $currency, self::SOURCE, $collection->id, $eventType, 1,
                        $occurredAt, $movements, $branch, $collection->recordedBy, null, $notes,
                    )['state'];
            }
            $unexpected = FinancialTransaction::query()->where('source_type', self::SOURCE)
                ->where('source_id', (string) $collection->id)
                ->whereIn('event_type', collect(FinancialEventType::correctedOrderDerived())->map->value)
                ->whereNotIn('event_type', [...array_keys($concepts), FinancialEventType::OrderCashCompanyDeliveryShareHeld->value])->exists();
            if ($unexpected) {
                return ['state' => 'conflict', 'reason' => 'Collection has corrected events for a different payment method.'];
            }
            if (in_array('conflict', $concepts, true)) {
                return ['state' => 'conflict', 'reason' => 'Corrected financial event conflicts with its immutable source.', 'concepts' => $concepts];
            }

            return [
                'state' => in_array('missing', $concepts, true) ? 'eligible' : 'complete',
                'invoice_id' => $invoice->id,
                'collection_id' => $collection->id,
                'method' => $collection->collection_method->value,
                'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s.uP'),
                'notes' => $notes,
                'concepts' => $concepts,
            ];
        } catch (FinancialIdempotencyConflictException $exception) {
            return ['state' => 'conflict', 'reason' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            return ['state' => 'unresolved', 'reason' => $exception->getMessage()];
        }
    }

    /** @return array<int, int> */
    public function unreversedLegacy(Order $order): array
    {
        $invoiceIds = $order->invoices()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $collectionIds = InvoiceCollection::query()->where('order_id', $order->id)->pluck('id')->map(fn ($id) => (string) $id)->all();

        return FinancialTransaction::query()->where(function ($query) use ($invoiceIds, $collectionIds): void {
            $query->where(fn ($invoiceEvents) => $invoiceEvents->where('source_type', OrderFinancialPostingService::INVOICE_SOURCE)
                ->whereIn('source_id', $invoiceIds)->whereIn('event_type', collect(FinancialEventType::orderDerived())->map->value))
                ->orWhere(fn ($cashEvents) => $cashEvents->where('source_type', self::SOURCE)
                    ->whereIn('source_id', $collectionIds)->whereIn('event_type', [
                        FinancialEventType::InvoiceCashCustodyReceived->value,
                        FinancialEventType::OrderCashCompanyDeliveryShareHeld->value,
                    ]));
        })->whereDoesntHave('reversal')->pluck('id')->all();
    }

    /** Verify the published 9B.6 Custody event against its immutable source before reversal. */
    public function inspectObsoleteHeld(Order $order, FinancialTransaction $event): bool
    {
        $assessment = $this->assess($order, true);
        if (! in_array($assessment['state'], ['eligible', 'complete'], true)
            || ($assessment['method'] ?? null) !== InvoiceCollectionMethod::Cash->value
            || $event->source_type !== self::SOURCE
            || $event->source_id !== (string) $assessment['collection_id']
            || $event->event_type !== FinancialEventType::OrderCashCompanyDeliveryShareHeld->value
            || $event->source_version !== 1) {
            return false;
        }
        $invoice = Invoice::query()->findOrFail($assessment['invoice_id']);
        $snapshot = $this->snapshots->read($invoice);
        $primary = collect($snapshot['captain_allocations'])->firstWhere('is_primary', true);
        $company = collect($snapshot['captain_allocations'])->reduce(
            fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['company_collectible']), BigDecimal::zero(),
        );
        if (! $primary || ! $company->isGreaterThan(0)) {
            return false;
        }
        $currency = Currency::query()->findOrFail($snapshot['currency_id']);
        $movements = $this->movements([
            $this->descriptor($primary['captain_id'], FinancialAccountKind::CaptainCashCustody, (string) $company->toScale(4)),
        ], $currency, false);
        if ($movements === null) {
            return false;
        }
        $collection = InvoiceCollection::query()->findOrFail($assessment['collection_id']);
        $branch = Branch::query()->findOrFail($snapshot['branch_id']);

        return $this->posting->inspectEvent(
            $currency, self::SOURCE, $collection->id, $event->event_type, 1,
            CarbonImmutable::parse($assessment['occurred_at']), $movements,
            $branch, $collection->recordedBy, null, $assessment['notes'],
        )['state'] === 'exact';
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function concepts(array $snapshot, InvoiceCollection $collection): array
    {
        $rows = $snapshot['captain_allocations'];
        $due = [];
        $shortfalls = [];
        $products = [];
        $entitlements = [];
        $company = BigDecimal::zero();
        foreach ($rows as $row) {
            $company = $company->plus($row['company_collectible']);
            if (BigDecimal::of($row['captain_shortfall'])->isGreaterThan(0)) {
                $shortfalls[] = $this->descriptor($row['captain_id'], FinancialAccountKind::CaptainCompanyReceivable, $row['captain_shortfall']);
            }
            if (BigDecimal::of($row['product_funding_share'])->isGreaterThan(0)) {
                $products[] = $this->descriptor($row['captain_id'], FinancialAccountKind::CaptainCompanyReceivable, $row['product_funding_share']);
            }
            if (BigDecimal::of($row['captain_entitlement'])->isGreaterThan(0)) {
                $entitlements[] = $this->descriptor($row['captain_id'], FinancialAccountKind::CaptainCompanyReceivable, $row['captain_entitlement']);
            }
        }
        $primary = collect($rows)->firstWhere('is_primary', true);
        if ($collection->collection_method === InvoiceCollectionMethod::Cash) {
            if ($company->isGreaterThan(0)) {
                $due[] = $this->descriptor($primary['captain_id'], FinancialAccountKind::CaptainDeposit, (string) $company->toScale(4), FinancialEntryDirection::Decrease);
            }

            return [
                FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value => $due,
                FinancialEventType::OrderCashDeliveryShortfallDue->value => $shortfalls,
            ];
        }

        return [
            FinancialEventType::OrderShamCashProductReimbursementDue->value => $products,
            FinancialEventType::OrderShamCashDeliveryEntitlementDue->value => $entitlements,
        ];
    }

    private function descriptor(int $captainId, FinancialAccountKind $kind, string $amount, FinancialEntryDirection $direction = FinancialEntryDirection::Increase): array
    {
        return ['captain_id' => $captainId, 'kind' => $kind, 'direction' => $direction, 'amount' => $amount];
    }

    private function canonicalJson(array $payload): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$entry) {
                $entry = $sort($entry);
            }

            return $value;
        };

        return json_encode($sort($payload), JSON_THROW_ON_ERROR);
    }

    private function movements(array $descriptors, Currency $currency, bool $create): ?array
    {
        $movements = [];
        foreach ($descriptors as $descriptor) {
            if ($create) {
                $captain = Captain::query()->findOrFail($descriptor['captain_id']);
                $account = $this->accounts->getOrCreate($this->accounts->ownerForCaptain($captain), $currency, $descriptor['kind']);
            } else {
                $owner = FinancialOwner::query()->where('captain_id', $descriptor['captain_id'])->first();
                $account = $owner ? FinancialAccount::query()->where('financial_owner_id', $owner->id)
                    ->where('currency_id', $currency->id)->where('account_kind', $descriptor['kind']->value)->first() : null;
            }
            if (! $account) {
                return null;
            }
            $movements[] = ['account' => $account, 'direction' => $descriptor['direction'], 'amount' => $descriptor['amount']];
        }

        return $movements;
    }
}
