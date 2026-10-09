<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\InvoiceFinancialCorrectionState;
use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\InvoiceCorrectionFinancialSnapshot;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceFinancialCorrectionService
{
    public function __construct(
        private readonly OrderFinancialSourceSnapshotService $snapshots,
        private readonly CorrectedOrderFinancialService $originalFinancials,
        private readonly InvoiceCorrectionFinancialPostingService $posting,
        private readonly OrderCancellationFinalizationService $cancellations,
        private readonly InvoiceCorrectionReceivableDispositionService $dispositions,
    ) {}

    /** Requesting does not change the authoritative Invoice, Collection, or ledger. */
    public function request(User $actor, Invoice $source, InvoiceFinancialCorrectionKind $kind, array $proposal, string $reason, string $explanation, string $requestKey): InvoiceFinancialCorrection
    {
        Gate::forUser($actor)->authorize('invoice_corrections.request');
        abort_unless(Invoice::query()->accessibleTo($actor)->whereKey($source)->exists(), 403);
        if (! Str::isUuid($requestKey) || trim($reason) === '' || trim($explanation) === '') {
            throw ValidationException::withMessages(['correction' => __('domain.a_request_key_reason_and_explanation_are_required')]);
        }
        $proposal = $this->canonicalProposal($kind, $proposal);

        return DB::transaction(function () use ($actor, $source, $kind, $proposal, $reason, $explanation, $requestKey): InvoiceFinancialCorrection {
            $order = Order::query()->lockForUpdate()->findOrFail($source->order_id);
            if (app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($order)) {
                throw ValidationException::withMessages(['invoice' => __('shared_orders.shared_correction_blocked')]);
            }
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($source->id);
            $collection = $invoice->collection()->lockForUpdate()->first();
            $existing = InvoiceFinancialCorrection::query()->where('request_key', $requestKey)->first();
            if ($existing) {
                if (! $collection || $existing->original_collection_id !== $collection->id || $existing->initiated_by !== $actor->id
                    || $existing->kind !== $kind || $existing->reason !== trim($reason)
                    || $existing->explanation !== trim($explanation)
                    || ! DB::scalar('SELECT ?::jsonb = ?::jsonb', [
                        json_encode($existing->proposal_payload, JSON_THROW_ON_ERROR),
                        json_encode($proposal, JSON_THROW_ON_ERROR),
                    ])) {
                    throw ValidationException::withMessages(['request_key' => __('domain.correction_request_key_already_has_different_facts')]);
                }

                return $existing;
            }
            if (! $collection || $invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => __('domain.correction_requires_the_current_collected_issued_invoice')]);
            }
            if ($kind === InvoiceFinancialCorrectionKind::CancelAndRefund && $order->billPayments()->exists()) {
                throw ValidationException::withMessages(['correction' => __('domain.completed_bill_payment_order_not_cancellable')]);
            }
            if (InvoiceFinancialCorrection::query()->where('original_collection_id', $collection->id)
                ->whereIn('state', ['REQUESTED', 'PREPARED', 'READY_TO_FINALIZE'])->exists()) {
                throw ValidationException::withMessages(['correction' => __('domain.an_unresolved_correction_already_exists_for_this_collection')]);
            }
            $snapshotId = DB::table('order_financial_source_snapshots')->where('invoice_id', $invoice->id)->value('id');
            $currency = Currency::query()->findOrFail($invoice->currency_id);

            return InvoiceFinancialCorrection::query()->create([
                'order_id' => $order->id,
                'original_invoice_id' => $invoice->id,
                'original_collection_id' => $collection->id,
                'original_snapshot_id' => $snapshotId,
                'branch_id' => $order->branch_id,
                'currency_id' => $currency->id,
                'currency_decimal_places' => $currency->decimal_places,
                'kind' => $kind,
                'state' => InvoiceFinancialCorrectionState::Requested,
                'request_key' => $requestKey,
                'reason' => trim($reason),
                'explanation' => trim($explanation),
                'proposal_payload' => $proposal,
                'initiated_by' => $actor->id,
                'initiated_at' => now(),
                'original_amount' => $collection->amount,
            ]);
        }, 3);
    }

    /** Freeze a proposal without reversing any money. */
    public function prepare(User $actor, InvoiceFinancialCorrection $source): InvoiceFinancialCorrection
    {
        Gate::forUser($actor)->authorize('invoice_corrections.prepare');

        return DB::transaction(function () use ($actor, $source): InvoiceFinancialCorrection {
            $order = Order::query()->lockForUpdate()->findOrFail($source->order_id);
            if (app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($order)) {
                throw ValidationException::withMessages(['invoice' => __('shared_orders.shared_correction_blocked')]);
            }
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($source->original_invoice_id);
            $collection = InvoiceCollection::query()->lockForUpdate()->findOrFail($source->original_collection_id);
            $correction = InvoiceFinancialCorrection::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertSource($order, $invoice, $collection, $correction);
            if ($correction->state === InvoiceFinancialCorrectionState::Prepared) {
                return $correction;
            }
            if ($correction->state !== InvoiceFinancialCorrectionState::Requested) {
                throw ValidationException::withMessages(['correction' => __('domain.only_a_requested_correction_may_be_prepared')]);
            }
            if ($correction->kind === InvoiceFinancialCorrectionKind::CancelAndRefund && $order->billPayments()->exists()) {
                throw ValidationException::withMessages(['correction' => __('domain.completed_bill_payment_order_not_cancellable')]);
            }
            $snapshot = $this->snapshots->read($invoice);
            $currency = Currency::query()->lockForUpdate()->findOrFail($correction->currency_id);
            $planned = $this->calculate($correction, $invoice, $snapshot, $currency);
            if (! $this->dispositions->active()) {
                $this->assertNoPaidDependency($correction, $planned, $snapshot);
            }
            $corrected = BigDecimal::of($planned['total']);
            $delta = $corrected->minus(BigDecimal::of($collection->amount));
            if ($delta->isGreaterThan(0)) {
                throw ValidationException::withMessages(['correction' => __('domain.additional_collection_required')]);
            }
            $preparedFacts = [
                'state' => InvoiceFinancialCorrectionState::Prepared,
                'corrected_amount' => (string) $corrected->toScale(4),
                'customer_delta' => (string) $delta->toScale(4),
                'proposal_hash' => hash('sha256', json_encode($planned, JSON_THROW_ON_ERROR)),
                'prepared_by' => $actor->id,
                'prepared_at' => now()->startOfSecond(),
            ];
            if ($this->dispositions->active()) {
                $this->dispositions->captureLocked($correction, $preparedFacts);
            } else {
                $correction->update($preparedFacts);
            }
            if ($delta->isZero()) {
                $correction->update(['state' => InvoiceFinancialCorrectionState::ReadyToFinalize]);
            }

            return $correction->fresh();
        }, 3);
    }

    public function abandon(User $actor, InvoiceFinancialCorrection $source): InvoiceFinancialCorrection
    {
        Gate::forUser($actor)->authorize('invoice_corrections.prepare');

        return DB::transaction(function () use ($source): InvoiceFinancialCorrection {
            Order::query()->lockForUpdate()->findOrFail($source->order_id);
            $correction = InvoiceFinancialCorrection::query()->lockForUpdate()->findOrFail($source->id);
            if (! in_array($correction->state, [InvoiceFinancialCorrectionState::Requested, InvoiceFinancialCorrectionState::Prepared], true)
                || $correction->refund()->exists()) {
                throw ValidationException::withMessages(['correction' => __('domain.a_correction_with_real_refund_or_finalization_cannot_be_abandoned')]);
            }
            $correction->update(['state' => InvoiceFinancialCorrectionState::Abandoned]);

            return $correction->fresh();
        }, 3);
    }

    /** The refund, if required, is a separate committed real-money source before this atomic step. */
    public function finalize(User $actor, InvoiceFinancialCorrection $source): InvoiceFinancialCorrection
    {
        Gate::forUser($actor)->authorize('invoice_corrections.finalize');

        return DB::transaction(function () use ($actor, $source): InvoiceFinancialCorrection {
            $order = Order::query()->lockForUpdate()->findOrFail($source->order_id);
            if (app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($order)) {
                throw ValidationException::withMessages(['invoice' => __('shared_orders.shared_correction_blocked')]);
            }
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($source->original_invoice_id);
            $collection = InvoiceCollection::query()->lockForUpdate()->findOrFail($source->original_collection_id);
            $correction = InvoiceFinancialCorrection::query()->lockForUpdate()->findOrFail($source->id);
            if ($correction->state === InvoiceFinancialCorrectionState::Completed) {
                return $correction;
            }
            if ($correction->state !== InvoiceFinancialCorrectionState::ReadyToFinalize) {
                throw ValidationException::withMessages(['correction' => __('domain.correction_is_not_ready_to_finalize')]);
            }
            $this->assertSource($order, $invoice, $collection, $correction);
            $original = $this->snapshots->read($invoice);
            $currency = Currency::query()->lockForUpdate()->findOrFail($correction->currency_id);
            $planned = $this->calculate($correction, $invoice, $original, $currency);
            if (! hash_equals($correction->proposal_hash, hash('sha256', json_encode($planned, JSON_THROW_ON_ERROR)))
                || ! BigDecimal::of($planned['total'])->isEqualTo(BigDecimal::of($correction->corrected_amount))) {
                throw ValidationException::withMessages(['correction' => __('domain.prepared_proposal_no_longer_matches_its_frozen_facts')]);
            }
            if ($correction->receivable_dispositions_captured) {
                $this->dispositions->assertFinalizableLocked($correction);
            } else {
                $this->assertNoPaidDependency($correction, $planned, $original);
            }
            $refund = $correction->refund()->lockForUpdate()->first();
            $requiredRefund = BigDecimal::of($correction->customer_delta)->negated();
            if ($requiredRefund->isGreaterThan(0)) {
                if (! $refund || ! BigDecimal::of($refund->amount)->isEqualTo($requiredRefund)) {
                    throw ValidationException::withMessages(['refund' => __('domain.the_exact_real_customer_refund_is_required_before_finalization')]);
                }
            } elseif ($refund) {
                throw ValidationException::withMessages(['refund' => __('domain.a_zero_delta_correction_cannot_contain_a_refund')]);
            }
            if ($correction->kind === InvoiceFinancialCorrectionKind::CancelAndRefund) {
                if (($order->operational_status === OrderOperationalStatus::Delivered && ! DB::scalar("SELECT to_regprocedure('captain_recovery_cancellation_allowed(bigint,boolean)') IS NOT NULL")) || $order->billPayments()->exists()
                    || ($order->operational_status === OrderOperationalStatus::Delivered && ! DB::scalar('SELECT captain_recovery_cancellation_allowed(?,true)', [$order->id]))) {
                    throw ValidationException::withMessages(['correction' => __('domain.completed_bill_payment_order_not_cancellable_or_order_already_delivered')]);
                }
                if (! $order->cancellationIntent()->exists()) {
                    throw ValidationException::withMessages(['correction' => __('domain.after_purchase_cancellation_intent_and_returns_are_required')]);
                }
            } elseif ($order->operational_status === OrderOperationalStatus::Delivered) {
                $assessment = $this->originalFinancials->assess($order);
                if ($assessment['state'] !== 'complete') {
                    throw ValidationException::withMessages(['correction' => __('domain.original_delivered_order_financial_events_are_not_complete')]);
                }
            }
            if ($order->operational_status === OrderOperationalStatus::Delivered) {
                $this->posting->reverseOriginalLocked($correction, $actor);
            }
            $now = now();
            $invoice->update([
                'status' => InvoiceStatus::Replaced,
                'replaced_at' => $now,
                'replaced_by' => $actor->id,
                'replacement_reason' => $correction->reason,
                'superseded_at' => $now,
            ]);
            $replacement = $correction->kind === InvoiceFinancialCorrectionKind::Reprice
                ? $this->createReplacementLocked($actor, $invoice, $planned, $correction, $now) : null;
            if ($replacement) {
                $invoice->update(['replaced_by_invoice_id' => $replacement->id]);
            }
            $correction->update([
                'replacement_invoice_id' => $replacement?->id,
                'state' => InvoiceFinancialCorrectionState::Completed,
                'finalized_by' => $actor->id,
                'finalized_at' => $now,
            ]);
            $payload = $planned + [
                'proposal_hash' => $correction->proposal_hash,
                'original_invoice_id' => $invoice->id,
                'replacement_invoice_id' => $replacement?->id,
                'primary_captain_id' => $original['primary_captain_id'],
                'currency_decimal_places' => $correction->currency_decimal_places,
            ];
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $hash = DB::scalar("SELECT encode(sha256(convert_to(?::jsonb::text,'UTF8')),'hex')", [$json]);
            InvoiceCorrectionFinancialSnapshot::query()->create([
                'invoice_financial_correction_id' => $correction->id,
                'order_id' => $order->id,
                'branch_id' => $correction->branch_id,
                'currency_id' => $currency->id,
                'original_invoice_id' => $invoice->id,
                'replacement_invoice_id' => $replacement?->id,
                'payload' => $payload,
                'payload_hash' => $hash,
                'captured_at' => $now,
            ]);
            if ($correction->kind === InvoiceFinancialCorrectionKind::CancelAndRefund) {
                if ($order->operational_status === OrderOperationalStatus::Delivered && $correction->receivable_contract_version === 2) {
                    $this->dispositions->postLocked($correction->fresh());
                }
                $this->cancellations->finalize($actor, $order);
            } else {
                $this->posting->postIfDeliveredLocked($order->fresh(), $correction->fresh());
            }

            return $correction->fresh();
        }, 3);
    }

    private function createReplacementLocked(User $actor, Invoice $original, array $planned, InvoiceFinancialCorrection $correction, mixed $issuedAt): Invoice
    {
        $replacement = Invoice::query()->create([
            'order_id' => $original->order_id,
            'invoice_number' => 'PENDING-'.Str::uuid(),
            'version' => ((int) Invoice::query()->where('order_id', $original->order_id)->max('version')) + 1,
            'status' => InvoiceStatus::Draft,
            'currency_id' => $correction->currency_id,
            'source_order_delivery_pricing_id' => $original->source_order_delivery_pricing_id,
            'source_items_fingerprint' => $correction->proposal_hash,
            'items_subtotal' => $planned['subtotal'],
            'delivery_total' => $planned['delivery_total'],
            'grand_total' => $planned['total'],
            'customer_name_snapshot' => $original->customer_name_snapshot,
            'customer_phone_snapshot' => $original->customer_phone_snapshot,
            'address_snapshot' => $original->address_snapshot,
            'area_snapshot' => $original->area_snapshot,
            'order_number_snapshot' => $original->order_number_snapshot,
            'ordered_at_snapshot' => $original->ordered_at_snapshot,
            'created_by' => $actor->id,
        ]);
        foreach ($planned['items'] as $index => $line) {
            $originalItem = $original->items()->findOrFail($line['invoice_item_id']);
            $replacement->items()->create([
                'order_item_id' => $originalItem->order_item_id,
                'description' => $originalItem->description,
                'unit_name_snapshot' => $originalItem->unit_name_snapshot,
                'quantity' => $originalItem->quantity,
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'currency_id' => $correction->currency_id,
                'sort_order' => $index + 1,
            ]);
        }
        $replacement->update([
            'invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad((string) $replacement->id, 8, '0', STR_PAD_LEFT),
            'status' => InvoiceStatus::Issued,
            'ready_at' => $issuedAt,
            'issued_at' => $issuedAt,
            'issued_by' => $actor->id,
        ]);

        return $replacement->fresh();
    }

    /** @return array{items: array<int, array<string, string|int>>, allocations: array<int, array<string, string|int>>, subtotal: string, delivery_total: string, total: string} */
    public function calculate(InvoiceFinancialCorrection $correction, Invoice $invoice, array $snapshot, Currency $currency): array
    {
        if ($currency->id !== $correction->currency_id || $currency->decimal_places !== $correction->currency_decimal_places
            || $snapshot['branch_id'] !== $correction->branch_id || $snapshot['currency_id'] !== $currency->id) {
            throw ValidationException::withMessages(['correction' => __('domain.historical_branch_or_currency_source_changed')]);
        }
        if ($correction->kind === InvoiceFinancialCorrectionKind::CancelAndRefund) {
            return ['items' => [], 'allocations' => [], 'subtotal' => '0.0000', 'delivery_total' => '0.0000', 'total' => '0.0000'];
        }
        $payload = $correction->proposal_payload;
        $items = $invoice->items()->orderBy('id')->get();
        $providedItems = collect($payload['items'] ?? [])->keyBy('invoice_item_id');
        $participants = collect($snapshot['captain_allocations'])->keyBy('order_captain_id');
        $providedAllocations = collect($payload['delivery'] ?? [])->keyBy('order_captain_id');
        if ($items->isEmpty() || $providedItems->count() !== $items->count()
            || $providedItems->keys()->sort()->values()->all() !== $items->pluck('id')->sort()->values()->all()
            || $providedAllocations->count() !== $participants->count()
            || $providedAllocations->keys()->sort()->values()->all() !== $participants->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages(['proposal' => __('domain.corrected_items_and_historical_captain_allocations_must_be_complete')]);
        }
        $subtotal = BigDecimal::zero();
        $lines = [];
        foreach ($items as $item) {
            $unitPrice = $this->money($providedItems[$item->id]['unit_price'] ?? null, $currency, false);
            $lineTotal = BigDecimal::of($item->quantity)->multipliedBy($unitPrice)->toScale($currency->decimal_places, RoundingMode::HalfUp);
            $subtotal = $subtotal->plus($lineTotal);
            $lines[] = ['invoice_item_id' => $item->id, 'unit_price' => (string) $unitPrice->toScale(4), 'line_total' => (string) $lineTotal->toScale(4)];
        }
        $delivery = BigDecimal::zero();
        $allocations = [];
        foreach ($participants as $orderCaptainId => $row) {
            $customer = $this->money($providedAllocations[$orderCaptainId]['customer_amount'] ?? null, $currency, true);
            $entitlement = BigDecimal::of($row['captain_entitlement']);
            $company = $customer->isGreaterThan($entitlement) ? $customer->minus($entitlement) : BigDecimal::zero();
            $shortfall = $entitlement->isGreaterThan($customer) ? $entitlement->minus($customer) : BigDecimal::zero();
            $delivery = $delivery->plus($customer);
            $allocations[] = [
                'order_captain_id' => $orderCaptainId,
                'captain_id' => $row['captain_id'],
                'customer_amount' => (string) $customer->toScale(4),
                'captain_entitlement' => (string) $entitlement->toScale(4),
                'company_collectible' => (string) $company->toScale(4),
                'captain_shortfall' => (string) $shortfall->toScale(4),
            ];
        }
        $minor = $subtotal->toScale($currency->decimal_places, RoundingMode::Unnecessary)->getUnscaledValue();
        [$base, $remainder] = $minor->quotientAndRemainder(count($allocations));
        foreach ($allocations as $index => &$allocation) {
            $allocation['product_funding_share'] = (string) BigDecimal::ofUnscaledValue(
                $base->plus($index < $remainder->toInt() ? 1 : 0), $currency->decimal_places)->toScale(4);
        }
        unset($allocation);
        $total = $subtotal->plus($delivery);
        if (! $total->isGreaterThan(0)) {
            throw ValidationException::withMessages(['proposal' => __('domain.use_cancel_and_refund_for_a_zero_customer_obligation')]);
        }

        return ['items' => $lines, 'allocations' => $allocations,
            'subtotal' => (string) $subtotal->toScale(4), 'delivery_total' => (string) $delivery->toScale(4),
            'total' => (string) $total->toScale(4)];
    }

    private function money(mixed $raw, Currency $currency, bool $allowZero): BigDecimal
    {
        if (! is_string($raw) || ! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw ValidationException::withMessages(['proposal' => __('domain.money_must_be_an_exact_decimal_string')]);
        }
        try {
            $value = BigDecimal::of($raw)->toScale($currency->decimal_places, RoundingMode::Unnecessary);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['proposal' => __('domain.money_exceeds_currency_precision')]);
        }
        if ($value->isLessThan(0) || (! $allowZero && $value->isZero())) {
            throw ValidationException::withMessages(['proposal' => __('domain.money_must_be_positive_for_items_and_nonnegative_for_delivery')]);
        }

        return $value;
    }

    private function canonicalProposal(InvoiceFinancialCorrectionKind $kind, array $proposal): array
    {
        if ($kind === InvoiceFinancialCorrectionKind::CancelAndRefund) {
            if ($proposal !== [] && $proposal !== ['items' => [], 'delivery' => []]) {
                throw ValidationException::withMessages(['proposal' => __('domain.cancellation_has_no_replacement_item_or_delivery_amounts')]);
            }

            return ['items' => [], 'delivery' => []];
        }
        if (! isset($proposal['items'], $proposal['delivery']) || ! is_array($proposal['items']) || ! is_array($proposal['delivery'])) {
            throw ValidationException::withMessages(['proposal' => __('domain.corrected_items_and_delivery_allocations_are_required')]);
        }
        if (collect($proposal['items'])->contains(fn ($line) => ! is_array($line))
            || collect($proposal['delivery'])->contains(fn ($line) => ! is_array($line))) {
            throw ValidationException::withMessages(['proposal' => __('domain.each_item_and_delivery_allocation_must_be_structured_data')]);
        }
        $items = collect($proposal['items'])->map(fn ($line) => [
            'invoice_item_id' => filter_var($line['invoice_item_id'] ?? null, FILTER_VALIDATE_INT),
            'unit_price' => $line['unit_price'] ?? null,
        ])->sortBy('invoice_item_id')->values()->all();
        $delivery = collect($proposal['delivery'])->map(fn ($line) => [
            'order_captain_id' => filter_var($line['order_captain_id'] ?? null, FILTER_VALIDATE_INT),
            'customer_amount' => $line['customer_amount'] ?? null,
        ])->sortBy('order_captain_id')->values()->all();
        if (collect($items)->pluck('invoice_item_id')->contains(false)
            || collect($delivery)->pluck('order_captain_id')->contains(false)) {
            throw ValidationException::withMessages(['proposal' => __('domain.proposal_identifiers_must_be_valid')]);
        }

        return ['items' => $items, 'delivery' => $delivery];
    }

    private function assertSource(Order $order, Invoice $invoice, InvoiceCollection $collection, InvoiceFinancialCorrection $correction): void
    {
        if ($order->id !== $correction->order_id || $invoice->order_id !== $order->id
            || $collection->invoice_id !== $invoice->id || $collection->order_id !== $order->id
            || $invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at
            || $collection->currency_id !== $correction->currency_id || $order->branch_id !== $correction->branch_id
            || ! BigDecimal::of($collection->amount)->isEqualTo(BigDecimal::of($correction->original_amount))) {
            throw ValidationException::withMessages(['correction' => __('domain.historical_correction_source_is_no_longer_authoritative')]);
        }
    }

    private function assertNoPaidDependency(InvoiceFinancialCorrection $correction, array $planned, array $snapshot): void
    {
        $events = FinancialTransaction::query()->where('source_type', 'INVOICE_COLLECTION')
            ->where('source_id', (string) $correction->original_collection_id)
            ->whereIn('event_type', [
                FinancialEventType::OrderCashDeliveryShortfallDue->value,
                FinancialEventType::OrderShamCashProductReimbursementDue->value,
                FinancialEventType::OrderShamCashDeliveryEntitlementDue->value,
            ])->orderBy('id')->get();
        if ($events->isEmpty()) {
            return;
        }
        $currency = Currency::query()->lockForUpdate()->findOrFail($correction->currency_id);
        $accountIds = DB::table('financial_entries')->whereIn('financial_transaction_id', $events->pluck('id'))
            ->orderBy('financial_account_id')->pluck('financial_account_id')->unique()->all();
        FinancialAccount::query()->whereKey($accountIds)->orderBy('id')->lockForUpdate()->get();
        $participants = collect($planned['allocations'])->keyBy('captain_id');
        $originalParticipants = collect($snapshot['captain_allocations'])->keyBy('captain_id');
        $productShares = [];
        if ($correction->kind === InvoiceFinancialCorrectionKind::Reprice) {
            $minor = BigDecimal::of($planned['subtotal'])->toScale($currency->decimal_places, RoundingMode::Unnecessary)->getUnscaledValue();
            [$base, $remainder] = $minor->quotientAndRemainder($originalParticipants->count());
            foreach ($originalParticipants->values() as $index => $row) {
                $productShares[$row['captain_id']] = (string) BigDecimal::ofUnscaledValue(
                    $base->plus($index < $remainder->toInt() ? 1 : 0), $currency->decimal_places)->toScale(4);
            }
        }
        foreach ($events as $event) {
            foreach ($event->entries()->where('direction', FinancialEntryDirection::Increase->value)->get() as $entry) {
                if ($entry->account->account_kind !== FinancialAccountKind::CaptainCompanyReceivable) {
                    continue;
                }
                $paid = (string) DB::table('captain_receivable_payout_allocations')->where('financial_entry_id', $entry->id)
                    ->selectRaw('COALESCE(SUM(amount),0) AS paid')->value('paid');
                if (! BigDecimal::of($paid)->isGreaterThan(0)) {
                    continue;
                }
                $captainId = $entry->account->owner->captain_id;
                $row = $participants->get($captainId);
                $corrected = match ($event->event_type) {
                    FinancialEventType::OrderCashDeliveryShortfallDue->value => $row['captain_shortfall'] ?? '0',
                    FinancialEventType::OrderShamCashProductReimbursementDue->value => $productShares[$captainId] ?? '0',
                    FinancialEventType::OrderShamCashDeliveryEntitlementDue->value => $row['captain_entitlement'] ?? '0',
                    default => '0',
                };
                $code = BigDecimal::of($corrected)->isLessThan(BigDecimal::of($paid))
                    ? 'CAPTAIN_RECOVERY_REQUIRED' : 'PAID_RECEIVABLE_CORRECTION_REQUIRED';
                throw ValidationException::withMessages(['correction' => __('domain.paid_correction_details', ['code' => $code, 'captain' => $captainId, 'branch' => $correction->branch_id, 'currency' => $currency->id, 'entry' => $entry->id, 'paid' => (string) $paid, 'corrected' => (string) $corrected])]);
            }
        }
    }
}
