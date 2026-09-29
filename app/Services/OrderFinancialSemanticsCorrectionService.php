<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderFinancialSemanticsCorrectionService
{
    private const REASON = 'Phase 9B.6 correction of normal Order financial semantics';

    private const HELD_REASON = 'Phase 9B.7 correction of obsolete normal CASH Custody';

    public function __construct(
        private readonly OrderFinancialPostingService $legacy,
        private readonly CorrectedOrderFinancialService $corrected,
        private readonly FinancialPostingService $posting,
    ) {}

    /** The dry-run path performs no inserts or updates. */
    public function correct(Order $source, bool $apply = false): array
    {
        return DB::transaction(function () use ($source, $apply): array {
            $order = Order::query()->lockForUpdate()->findOrFail($source->id);
            $invoiceIds = $order->invoices()->pluck('id')->map(fn ($id) => (string) $id)->all();
            $collections = InvoiceCollection::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $collectionIds = $collections->pluck('id')->map(fn ($id) => (string) $id)->all();
            $legacy = FinancialTransaction::query()
                ->where(fn ($query) => $query
                    ->where(fn ($events) => $events->where('source_type', OrderFinancialPostingService::INVOICE_SOURCE)
                        ->whereIn('source_id', $invoiceIds)->whereIn('event_type', collect(FinancialEventType::orderDerived())->map->value))
                    ->orWhere(fn ($events) => $events->where('source_type', OrderFinancialPostingService::COLLECTION_SOURCE)
                        ->whereIn('source_id', $collectionIds)->where('event_type', FinancialEventType::InvoiceCashCustodyReceived->value)))
                ->orderBy('id')->get();
            $pending = $legacy->filter(fn ($event) => ! $event->reversal()->exists());
            $held = FinancialTransaction::query()->where('source_type', CorrectedOrderFinancialService::SOURCE)
                ->whereIn('source_id', $collectionIds)
                ->where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareHeld->value)
                ->orderBy('id')->get();
            $pendingHeld = $held->filter(fn ($event) => ! $event->reversal()->exists());
            $currentInvoice = $order->currentInvoice()->first();
            $details = [
                'order_id' => $order->id,
                'invoice_id' => $currentInvoice?->id,
                'invoice_version' => $currentInvoice?->version,
                'collections' => $collections->map(fn ($collection) => ['id' => $collection->id, 'method' => $collection->collection_method->value])->all(),
                'legacy_event_ids' => $legacy->pluck('id')->all(),
                'reverse_ids' => $pending->pluck('id')->all(),
                'obsolete_held_ids' => $held->pluck('id')->all(),
                'reverse_held_ids' => $pendingHeld->pluck('id')->all(),
            ];
            try {
                $paidEventId = DB::table('captain_receivable_payout_allocations as a')
                    ->join('financial_entries as e', 'e.id', '=', 'a.financial_entry_id')
                    ->whereIn('e.financial_transaction_id', $pending->pluck('id'))
                    ->value('e.financial_transaction_id');
                if ($paidEventId !== null) {
                    throw new FinancialIdempotencyConflictException("PAYOUT_ALREADY_EXISTS: Receivable event {$paidEventId} is paid; MANUAL_REVIEW required.");
                }
                foreach ($legacy as $event) {
                    $this->validateLegacyEvent($order, $event, $collections);
                    $reversal = $event->reversal()->first();
                    if ($reversal && ($reversal->source_type !== 'ORDER_FINANCIAL_SEMANTICS_CORRECTION'
                        || $reversal->source_id !== (string) $event->id
                        || $reversal->event_type !== 'REVERSE_LEGACY_ORDER_EVENT'
                        || $reversal->source_version !== 1
                        || $reversal->reason !== self::REASON)) {
                        throw new FinancialIdempotencyConflictException("Legacy event {$event->id} has an unexpected reversal.");
                    }
                }
                foreach ($held as $event) {
                    if (! $this->corrected->inspectObsoleteHeld($order, $event)) {
                        throw new FinancialIdempotencyConflictException("Obsolete Custody event {$event->id} differs from its immutable source.");
                    }
                    $reversal = $event->reversal()->first();
                    if ($reversal && ($reversal->source_type !== 'ORDER_FINANCIAL_SEMANTICS_CORRECTION'
                        || $reversal->source_id !== (string) $event->id
                        || $reversal->event_type !== 'REVERSE_OBSOLETE_CASH_CUSTODY'
                        || $reversal->source_version !== 1
                        || $reversal->reason !== self::HELD_REASON)) {
                        throw new FinancialIdempotencyConflictException("Obsolete Custody event {$event->id} has an unexpected reversal.");
                    }
                }
                $assessment = $this->corrected->assess($order, true);
                if (in_array($assessment['state'], ['conflict', 'unresolved'], true)) {
                    throw new FinancialIdempotencyConflictException($assessment['reason']);
                }
                $details['corrected_state'] = $assessment['state'];
                $details['corrected_events'] = collect($assessment['concepts'] ?? [])->filter(fn ($state) => $state === 'missing')->keys()->all();
                if ($pending->isNotEmpty() && ($held->isNotEmpty() || in_array('exact', $assessment['concepts'] ?? [], true))) {
                    throw new FinancialIdempotencyConflictException('Phase 9B history overlaps a partial corrected event set.');
                }
                if ($pendingHeld->isNotEmpty()) {
                    if ($pendingHeld->count() !== 1 || $pending->isNotEmpty()
                        || ($assessment['concepts'][FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value] ?? null) !== 'missing'
                        || collect($assessment['concepts'] ?? [])->except([FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value])
                            ->contains(fn ($state) => $state === 'missing')) {
                        throw new FinancialIdempotencyConflictException('Obsolete Custody overlaps a partial or conflicting final event set.');
                    }
                    $this->assertCustodyUnconsumed($pendingHeld->first());
                } elseif ($held->isNotEmpty() && $assessment['state'] === 'eligible') {
                    throw new FinancialIdempotencyConflictException('Obsolete Custody was reversed without the final Deposit event.');
                }
                if ($pending->isEmpty() && $pendingHeld->isEmpty()) {
                    $hasFinalEvent = in_array('exact', $assessment['concepts'] ?? [], true);

                    return $details + ['state' => $assessment['state'] === 'complete' && $hasFinalEvent
                        ? 'already_9b7' : 'no_correction_required'];
                }
                if (! $apply) {
                    return $details + ['state' => $pending->isNotEmpty() ? 'legacy_9b_correction_required' : 'phase_9b6_correction_required'];
                }
                foreach ($pending as $event) {
                    $this->posting->reverse(
                        $event, 'ORDER_FINANCIAL_SEMANTICS_CORRECTION', $event->id,
                        'REVERSE_LEGACY_ORDER_EVENT', 1, now(), self::REASON,
                        $order->branch, null, "Order {$order->id}; original {$event->id}",
                    );
                }
                foreach ($pendingHeld as $event) {
                    $this->posting->reverse(
                        $event, 'ORDER_FINANCIAL_SEMANTICS_CORRECTION', $event->id,
                        'REVERSE_OBSOLETE_CASH_CUSTODY', 1, now(), self::HELD_REASON,
                        null, null, "Order {$order->id}; original {$event->id}",
                    );
                }
                if ($assessment['state'] === 'eligible') {
                    $this->corrected->postIfReady($order);
                }

                return $details + ['state' => $assessment['state'] === 'eligible' ? 'corrected' : 'reversed_waiting'];
            } catch (FinancialIdempotencyConflictException $exception) {
                if ($apply) {
                    throw $exception;
                }

                return $details + ['state' => str_contains($exception->getMessage(), 'PAYOUT_ALREADY_EXISTS') ? 'manual_review' : 'conflict', 'reason' => $exception->getMessage()];
            } catch (FinancialLedgerException $exception) {
                if ($apply) {
                    throw $exception;
                }

                return $details + ['state' => 'unresolved', 'reason' => $exception->getMessage()];
            }
        }, 3);
    }

    private function assertCustodyUnconsumed(FinancialTransaction $event): void
    {
        $entry = $event->entries()->sole();
        if ($entry->account->account_kind !== FinancialAccountKind::CaptainCashCustody
            || $entry->direction !== FinancialEntryDirection::Increase) {
            throw new FinancialIdempotencyConflictException("Obsolete Custody event {$event->id} has an invalid movement.");
        }
        // Posting takes Currency then Account locks. Hold both while checking later
        // movements and creating the linked reversal, so an unattributed spend cannot race this decision.
        DB::table('currencies')->where('id', $event->currency_id)->lockForUpdate()->first();
        DB::table('financial_accounts')->where('id', $entry->financial_account_id)->lockForUpdate()->first();
        $hasUnexplainedDecrease = DB::table('financial_entries as e')
            ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->where('e.financial_account_id', $entry->financial_account_id)
            ->where('e.direction', FinancialEntryDirection::Decrease->value)
            ->where('t.branch_id', $event->branch_id)
            ->where('t.id', '>', $event->id)
            // A reversal is attributed by its immutable reversal_of_transaction_id,
            // not by its label or by an aggregate account balance.
            ->whereNull('t.reversal_of_transaction_id')
            ->where(fn ($query) => $query
                ->where('t.event_type', '<>', FinancialEventType::BillPaymentPrincipalHandedOver->value)
                ->orWhere('t.source_type', '<>', 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER'))
            ->exists();
        if ($hasUnexplainedDecrease) {
            throw new FinancialIdempotencyConflictException("Obsolete Custody event {$event->id} may already have been consumed; manual review required.");
        }
    }

    private function validateLegacyEvent(Order $order, FinancialTransaction $event, $collections): void
    {
        if ($event->source_type === OrderFinancialPostingService::INVOICE_SOURCE) {
            $invoice = Invoice::query()->where('order_id', $order->id)->whereKey($event->source_id)->first();
            if (! $invoice || $invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at
                || $event->source_version !== $invoice->version) {
                throw new FinancialIdempotencyConflictException("Legacy Invoice event {$event->id} has an invalid source.");
            }
            $assessment = $this->legacy->assessOrder($order);
            if (($assessment['concepts'][$event->event_type]['state'] ?? null) !== 'exact') {
                throw new FinancialIdempotencyConflictException("Legacy Invoice event {$event->id} differs from its original source payload.");
            }

            return;
        }
        $collection = $collections->firstWhere('id', (int) $event->source_id);
        if (! $collection || $collection->collection_method !== InvoiceCollectionMethod::Cash) {
            throw new FinancialIdempotencyConflictException("Legacy custody event {$event->id} has no valid CASH Collection.");
        }
        $assessment = $this->legacy->assessCashCollection($collection);
        if (($assessment['state'] ?? null) !== 'exact' || $event->source_version !== 1) {
            throw new FinancialIdempotencyConflictException("Legacy custody event {$event->id} differs from its original source payload.");
        }
    }
}
