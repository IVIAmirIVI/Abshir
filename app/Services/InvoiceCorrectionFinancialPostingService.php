<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionState;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\InvoiceCollection;
use App\Models\InvoiceCorrectionFinancialSnapshot;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCorrectionFinancialPostingService
{
    public function __construct(
        private readonly FinancialPostingService $posting,
        private readonly FinancialAccountService $accounts,
        private readonly InvoiceCorrectionReceivableDispositionService $dispositions,
    ) {}

    /** Called under the Order lock before replacing the original Invoice. */
    public function reverseOriginalLocked(InvoiceFinancialCorrection $correction, User $actor): void
    {
        $eventTypes = collect(FinancialEventType::correctedOrderDerived())->map->value;
        $events = FinancialTransaction::query()->where('source_type', 'INVOICE_COLLECTION')
            ->where('source_id', (string) $correction->original_collection_id)
            ->whereIn('event_type', $eventTypes)->orderBy('id')->get();
        foreach ($events as $event) {
            if ($correction->receivable_contract_version === 2 && $event->event_type !== FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value) {
                continue;
            }
            $paid = DB::table('captain_receivable_payout_allocations as a')
                ->join('financial_entries as e', 'e.id', '=', 'a.financial_entry_id')
                ->where('e.financial_transaction_id', $event->id)->exists();
            if ($paid) {
                throw ValidationException::withMessages(['correction' => __('domain.paid_receivable_correction_required')]);
            }
            if ($event->reversal()->exists()) {
                throw ValidationException::withMessages(['correction' => __('domain.original_financial_event_has_another_reversal')]);
            }
            $reversal = $this->posting->reverse($event, 'INVOICE_FINANCIAL_CORRECTION_REVERSAL', $event->id,
                FinancialEventType::InvoiceCorrectionReverseOriginal->value, 1, now(), $correction->reason,
                Branch::query()->findOrFail($correction->branch_id), $actor,
                "InvoiceFinancialCorrection {$correction->id}");
            DB::table('invoice_correction_reversals')->insert([
                'invoice_financial_correction_id' => $correction->id,
                'original_transaction_id' => $event->id,
                'reversal_transaction_id' => $reversal->id,
            ]);
        }
    }

    /** Read the immutable corrected snapshot; no original Collection is posted twice. */
    public function postIfDeliveredLocked(Order $order, InvoiceFinancialCorrection $correction): array
    {
        if ($order->operational_status !== OrderOperationalStatus::Delivered) {
            return [];
        }
        if ($correction->state !== InvoiceFinancialCorrectionState::Completed) {
            throw ValidationException::withMessages(['correction' => __('domain.correction_must_be_completed_before_corrected_financial_posting')]);
        }
        $snapshot = InvoiceCorrectionFinancialSnapshot::query()
            ->where('invoice_financial_correction_id', $correction->id)->firstOrFail();
        $payload = $snapshot->payload;
        $expectedHash = DB::scalar("SELECT encode(sha256(convert_to(?::jsonb::text,'UTF8')),'hex')", [json_encode($payload, JSON_THROW_ON_ERROR)]);
        if (! hash_equals($snapshot->payload_hash, (string) $expectedHash)
            || $snapshot->branch_id !== $order->branch_id || $snapshot->currency_id !== $correction->currency_id
            || ($payload['proposal_hash'] ?? null) !== $correction->proposal_hash) {
            throw ValidationException::withMessages(['correction' => __('domain.corrected_financial_source_snapshot_differs_from_its_frozen_facts')]);
        }
        $currency = Currency::query()->lockForUpdate()->findOrFail($correction->currency_id);
        $branch = Branch::query()->findOrFail($correction->branch_id);
        $collection = InvoiceCollection::query()->findOrFail($correction->original_collection_id);
        $rows = $payload['allocations'];
        $concepts = [];
        if ($collection->collection_method === InvoiceCollectionMethod::Cash) {
            $company = collect($rows)->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['company_collectible']), BigDecimal::zero());
            $primary = collect($rows)->firstWhere('captain_id', $payload['primary_captain_id']);
            if (! $primary) {
                throw ValidationException::withMessages(['correction' => __('domain.historical_primary_collection_captain_is_missing')]);
            }
            $concepts[FinancialEventType::InvoiceCorrectionCashDepositDebit->value] = $company->isGreaterThan(0)
                ? [$this->movement($primary['captain_id'], FinancialAccountKind::CaptainDeposit, FinancialEntryDirection::Decrease, (string) $company->toScale(4), $currency)] : [];
            $concepts[FinancialEventType::InvoiceCorrectionCashShortfallDue->value] = collect($rows)
                ->filter(fn (array $row) => BigDecimal::of($row['captain_shortfall'])->isGreaterThan(0))
                ->map(fn (array $row) => $this->movement($row['captain_id'], FinancialAccountKind::CaptainCompanyReceivable,
                    FinancialEntryDirection::Increase, $row['captain_shortfall'], $currency))->values()->all();
        } else {
            $minor = BigDecimal::of($payload['subtotal'])->toScale($currency->decimal_places, RoundingMode::Unnecessary)->getUnscaledValue();
            [$base, $remainder] = $minor->quotientAndRemainder(count($rows));
            $product = [];
            $delivery = [];
            foreach ($rows as $index => $row) {
                $share = BigDecimal::ofUnscaledValue($base->plus($index < $remainder->toInt() ? 1 : 0), $currency->decimal_places);
                if ($share->isGreaterThan(0)) {
                    $product[] = $this->movement($row['captain_id'], FinancialAccountKind::CaptainCompanyReceivable,
                        FinancialEntryDirection::Increase, (string) $share->toScale(4), $currency);
                }
                if (BigDecimal::of($row['captain_entitlement'])->isGreaterThan(0)) {
                    $delivery[] = $this->movement($row['captain_id'], FinancialAccountKind::CaptainCompanyReceivable,
                        FinancialEntryDirection::Increase, $row['captain_entitlement'], $currency);
                }
            }
            $concepts[FinancialEventType::InvoiceCorrectionShamProductDue->value] = $product;
            $concepts[FinancialEventType::InvoiceCorrectionShamDeliveryDue->value] = $delivery;
        }
        $posted = [];
        if ($correction->receivable_contract_version === 2) {
            $concepts = array_intersect_key($concepts, [FinancialEventType::InvoiceCorrectionCashDepositDebit->value => true]);
            $posted = $this->dispositions->postLocked($correction);
        }
        foreach ($concepts as $eventType => $movements) {
            if ($movements !== []) {
                $posted[] = $this->posting->postEvent($currency, 'INVOICE_FINANCIAL_CORRECTION', $correction->id,
                    $eventType, 1, now(), $movements, $branch, null, null,
                    json_encode(['correction_id' => $correction->id, 'snapshot_hash' => $snapshot->payload_hash], JSON_THROW_ON_ERROR));
            }
        }

        return $posted;
    }

    private function movement(int $captainId, FinancialAccountKind $kind, FinancialEntryDirection $direction, string $amount, Currency $currency): array
    {
        $captain = Captain::query()->findOrFail($captainId);
        $account = $this->accounts->getOrCreate($this->accounts->ownerForCaptain($captain), $currency, $kind);

        return ['account' => $account, 'direction' => $direction, 'amount' => $amount];
    }
}
