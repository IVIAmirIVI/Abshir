<?php

namespace App\Services;

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
            $currentInvoice = $order->currentInvoice()->first();
            $details = [
                'order_id' => $order->id,
                'invoice_id' => $currentInvoice?->id,
                'invoice_version' => $currentInvoice?->version,
                'collections' => $collections->map(fn ($collection) => ['id' => $collection->id, 'method' => $collection->collection_method->value])->all(),
                'legacy_event_ids' => $legacy->pluck('id')->all(),
                'reverse_ids' => $pending->pluck('id')->all(),
            ];
            try {
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
                $assessment = $this->corrected->assess($order, true);
                if (in_array($assessment['state'], ['conflict', 'unresolved'], true)) {
                    throw new FinancialIdempotencyConflictException($assessment['reason']);
                }
                $details['corrected_state'] = $assessment['state'];
                $details['corrected_events'] = collect($assessment['concepts'] ?? [])->filter(fn ($state) => $state === 'missing')->keys()->all();
                if ($pending->isEmpty()) {
                    return $details + ['state' => $assessment['state'] === 'eligible' ? 'complete_legacy_backfill_required' : 'complete'];
                }
                if (! $apply) {
                    return $details + ['state' => 'would_correct'];
                }
                foreach ($pending as $event) {
                    $this->posting->reverse(
                        $event, 'ORDER_FINANCIAL_SEMANTICS_CORRECTION', $event->id,
                        'REVERSE_LEGACY_ORDER_EVENT', 1, now(), self::REASON,
                        $order->branch, null, "Order {$order->id}; original {$event->id}",
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

                return $details + ['state' => 'conflict', 'reason' => $exception->getMessage()];
            } catch (FinancialLedgerException $exception) {
                if ($apply) {
                    throw $exception;
                }

                return $details + ['state' => 'unresolved', 'reason' => $exception->getMessage()];
            }
        }, 3);
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
