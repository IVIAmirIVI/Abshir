<?php

namespace App\Services;

use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\FinancialTransactionKind;
use App\Enums\FinancialTransactionStatus;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FinancialPostingService
{
    /** Validate a planned movement without requiring or creating an Account. */
    public function validatePlannedAmount(Currency $currency, mixed $rawAmount): string
    {
        $this->validateCurrency($currency);
        if (is_float($rawAmount)) {
            throw new FinancialLedgerException('Financial amounts must not be provided as floats.');
        }
        try {
            $amount = BigDecimal::of($rawAmount ?? '');
        } catch (\Throwable) {
            throw new FinancialLedgerException('Financial movement direction or amount is invalid.');
        }
        if (! $amount->isGreaterThan(0)) {
            throw new FinancialLedgerException('Financial movement amounts must be greater than zero.');
        }
        if ($amount->strippedOfTrailingZeros()->getScale() > $currency->decimal_places) {
            throw new FinancialLedgerException('Financial amount exceeds the Currency decimal precision.');
        }

        return (string) $amount->toScale(4, RoundingMode::Unnecessary);
    }

    /** @return array{state: 'missing'|'exact'|'conflict', transaction: ?FinancialTransaction} */
    public function inspectEvent(
        Currency $currency,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        int $sourceVersion,
        CarbonInterface $occurredAt,
        array $movements,
        ?Branch $branch = null,
        ?User $recordedBy = null,
        ?string $reason = null,
        ?string $notes = null,
    ): array {
        $this->validateIdentity($sourceType, $sourceId, $eventType, $sourceVersion);
        $this->validateCurrency($currency);
        $normalized = $this->normalizeMovements($currency, $movements);
        $payloadHash = $this->payloadHash(
            FinancialTransactionKind::Event,
            $currency,
            $sourceType,
            $sourceId,
            $eventType,
            $sourceVersion,
            $occurredAt,
            $normalized,
            $branch,
            $recordedBy,
            null,
            $reason,
            $notes,
        );

        $transactions = FinancialTransaction::query()
            ->where('source_type', trim($sourceType))
            ->where('source_id', (string) $sourceId)
            ->where('event_type', trim($eventType))
            ->get();
        if ($transactions->isEmpty()) {
            return ['state' => 'missing', 'transaction' => null];
        }
        $transaction = $transactions->firstWhere('source_version', $sourceVersion);
        if ($transactions->count() !== 1
            || ! $transaction
            || $transaction->status !== FinancialTransactionStatus::Posted
            || ! hash_equals($transaction->payload_hash, $payloadHash)) {
            return ['state' => 'conflict', 'transaction' => $transaction];
        }

        return ['state' => 'exact', 'transaction' => $transaction];
    }

    public function postEvent(
        Currency $currency,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        int $sourceVersion,
        CarbonInterface $occurredAt,
        array $movements,
        ?Branch $branch = null,
        ?User $recordedBy = null,
        ?string $reason = null,
        ?string $notes = null,
    ): FinancialTransaction {
        return $this->post(
            FinancialTransactionKind::Event,
            $currency,
            $sourceType,
            $sourceId,
            $eventType,
            $sourceVersion,
            $occurredAt,
            $movements,
            $branch,
            $recordedBy,
            null,
            $reason,
            $notes,
        );
    }

    public function postTransfer(
        Currency $currency,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        int $sourceVersion,
        CarbonInterface $occurredAt,
        array $movements,
        ?Branch $branch = null,
        ?User $recordedBy = null,
        ?string $reason = null,
        ?string $notes = null,
    ): FinancialTransaction {
        return $this->post(
            FinancialTransactionKind::Transfer,
            $currency,
            $sourceType,
            $sourceId,
            $eventType,
            $sourceVersion,
            $occurredAt,
            $movements,
            $branch,
            $recordedBy,
            null,
            $reason,
            $notes,
        );
    }

    public function reverse(
        FinancialTransaction $original,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        int $sourceVersion,
        CarbonInterface $occurredAt,
        string $reason,
        ?Branch $branch = null,
        ?User $recordedBy = null,
        ?string $notes = null,
    ): FinancialTransaction {
        if (trim($reason) === '') {
            throw new FinancialLedgerException('A reversal reason is required.');
        }

        return DB::transaction(function () use ($original, $sourceType, $sourceId, $eventType, $sourceVersion, $occurredAt, $reason, $branch, $recordedBy, $notes): FinancialTransaction {
            Currency::query()->lockForUpdate()->findOrFail($original->currency_id);
            $original = FinancialTransaction::query()->lockForUpdate()->findOrFail($original->id);
            if ($original->transaction_kind === FinancialTransactionKind::Reversal) {
                throw new FinancialLedgerException('A reversal transaction cannot itself be reversed.');
            }
            if (in_array($original->event_type, [
                FinancialEventType::CaptainDepositReceived->value,
                FinancialEventType::CaptainReceivablePaid->value,
                FinancialEventType::ReceivableUnpaidRemainderRetired->value,
                FinancialEventType::ReceivableCorrectedResidualDue->value,
            ], true)) {
                throw new FinancialIdempotencyConflictException('FINANCIAL_SOURCE_REQUIRES_CONTROLLED_CORRECTION: this Captain cash operation cannot be reversed generically.');
            }
            if (FinancialTransaction::query()->where('reversal_of_transaction_id', $original->id)->exists()) {
                throw new FinancialLedgerException('This financial transaction has already been reversed.');
            }
            if (DB::table('captain_receivable_payout_allocations as a')
                ->join('financial_entries as e', 'e.id', '=', 'a.financial_entry_id')
                ->where('e.financial_transaction_id', $original->id)->exists()) {
                throw new FinancialIdempotencyConflictException('PAYOUT_ALREADY_EXISTS: paid Receivable source requires manual review.');
            }
            if ($branch !== null && $branch->id !== $original->branch_id) {
                throw new FinancialLedgerException('A reversal must retain the original transaction Branch.');
            }
            // The original immutable transaction, never the caller, owns reversal Branch placement.
            $branch = $original->branch_id ? Branch::query()->findOrFail($original->branch_id) : null;

            $movements = $original->entries()->orderBy('financial_account_id')->get()->map(fn ($entry): array => [
                'account' => $entry->account,
                'direction' => $entry->direction->opposite(),
                'amount' => $entry->amount,
            ])->all();

            return $this->postWithinTransaction(
                FinancialTransactionKind::Reversal,
                $original->currency,
                $sourceType,
                $sourceId,
                $eventType,
                $sourceVersion,
                $occurredAt,
                $movements,
                $branch,
                $recordedBy,
                $original,
                trim($reason),
                $notes,
            );
        }, 3);
    }

    private function post(
        FinancialTransactionKind $kind,
        Currency $currency,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        int $sourceVersion,
        CarbonInterface $occurredAt,
        array $movements,
        ?Branch $branch,
        ?User $recordedBy,
        ?FinancialTransaction $reversalOf,
        ?string $reason,
        ?string $notes,
    ): FinancialTransaction {
        return DB::transaction(fn (): FinancialTransaction => $this->postWithinTransaction(
            $kind,
            $currency,
            $sourceType,
            $sourceId,
            $eventType,
            $sourceVersion,
            $occurredAt,
            $movements,
            $branch,
            $recordedBy,
            $reversalOf,
            $reason,
            $notes,
        ), 3);
    }

    private function postWithinTransaction(
        FinancialTransactionKind $kind,
        Currency $currency,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        int $sourceVersion,
        CarbonInterface $occurredAt,
        array $movements,
        ?Branch $branch,
        ?User $recordedBy,
        ?FinancialTransaction $reversalOf,
        ?string $reason,
        ?string $notes,
    ): FinancialTransaction {
        $this->validateIdentity($sourceType, $sourceId, $eventType, $sourceVersion);
        $this->lockOrderEventSource($kind, $sourceType, $sourceId, $eventType);
        $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
        $this->validateCurrency($currency);
        $normalized = $this->normalizeMovements($currency, $movements);
        $this->validateKind($kind, $normalized);

        $occurredAt = CarbonImmutable::instance($occurredAt)->utc();
        $payloadHash = $this->payloadHash($kind, $currency, $sourceType, $sourceId, $eventType, $sourceVersion, $occurredAt, $normalized, $branch, $recordedBy, $reversalOf, $reason, $notes);
        $preciseOrderEvent = in_array($eventType, [
            ...array_map(fn (FinancialEventType $type) => $type->value, FinancialEventType::correctedOrderDerived()),
            FinancialEventType::CaptainDepositReceived->value,
            FinancialEventType::CaptainReceivablePaid->value,
            FinancialEventType::ReceivableUnpaidRemainderRetired->value,
            FinancialEventType::ReceivableCorrectedResidualDue->value,
        ], true);

        $identity = [
            'source_type' => trim($sourceType),
            'source_id' => (string) $sourceId,
            'event_type' => trim($eventType),
            'source_version' => $sourceVersion,
        ];
        $inserted = DB::table('financial_transactions')->insertOrIgnore($identity + [
            'transaction_kind' => $kind->value,
            'status' => FinancialTransactionStatus::Draft->value,
            'currency_id' => $currency->id,
            'branch_id' => $branch?->id,
            'payload_hash' => $payloadHash,
            'occurred_at' => $occurredAt->format($preciseOrderEvent ? 'Y-m-d H:i:s.uP' : 'Y-m-d H:i:sP'),
            'recorded_by_user_id' => $recordedBy?->id,
            'reversal_of_transaction_id' => $reversalOf?->id,
            'reason' => $reason,
            'notes' => $notes,
            'created_at' => now(),
        ]);

        $transaction = FinancialTransaction::query()->where($identity)->lockForUpdate()->first();
        if (! $transaction) {
            throw new FinancialLedgerException('The financial source identity conflicted with another ledger invariant.');
        }
        if (! hash_equals($transaction->payload_hash, $payloadHash)) {
            throw new FinancialIdempotencyConflictException('The financial source identity already exists with a different payload.');
        }
        if ($inserted === 0) {
            if ($transaction->status !== FinancialTransactionStatus::Posted) {
                throw new FinancialLedgerException('An idempotent financial transaction is not fully POSTED.');
            }

            return $transaction->load('entries');
        }

        $accounts = FinancialAccount::query()
            ->whereKey(collect($normalized)->pluck('financial_account_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($accounts->count() !== count($normalized)) {
            throw new FinancialLedgerException('Every financial movement must reference an existing account.');
        }

        foreach ($normalized as $movement) {
            $account = $accounts->get($movement['financial_account_id']);
            if (! $account || $account->currency_id !== $currency->id) {
                throw new FinancialLedgerException('Transaction and Account currencies must match.');
            }
            DB::table('financial_entries')->insert([
                'financial_transaction_id' => $transaction->id,
                'financial_account_id' => $account->id,
                'currency_id' => $currency->id,
                'direction' => $movement['direction'],
                'amount' => $movement['amount'],
                'created_at' => now(),
            ]);
        }

        $finalized = DB::table('financial_transactions')
            ->where('id', $transaction->id)
            ->where('status', FinancialTransactionStatus::Draft->value)
            ->update([
                'status' => FinancialTransactionStatus::Posted->value,
                'posted_at' => now(),
            ]);
        if ($finalized !== 1) {
            throw new FinancialLedgerException('The financial transaction could not be finalized.');
        }

        return $transaction->fresh()->load('entries');
    }

    /** Keep the production source -> Currency -> Account order before PostgreSQL's insert guard. */
    private function lockOrderEventSource(FinancialTransactionKind $kind, string $sourceType, string|int $sourceId, string $eventType): void
    {
        if ($kind !== FinancialTransactionKind::Event || ! ctype_digit((string) $sourceId)
            || strlen((string) $sourceId) > 18) {
            return;
        }
        $id = (string) $sourceId;
        if ($sourceType === 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION'
            && in_array($eventType, [FinancialEventType::ReceivableUnpaidRemainderRetired->value, FinancialEventType::ReceivableCorrectedResidualDue->value], true)) {
            $orderId = DB::table('invoice_correction_receivable_dispositions as d')
                ->join('invoice_financial_corrections as c', 'c.id', '=', 'd.invoice_financial_correction_id')
                ->where('d.id', $id)->value('c.order_id');
        } elseif ($sourceType === 'CUSTOMER_INVOICE'
            && in_array($eventType, array_map(fn (FinancialEventType $type) => $type->value, FinancialEventType::orderDerived()), true)) {
            $orderId = DB::table('invoices')->where('id', $id)->value('order_id');
        } elseif ($sourceType === 'INVOICE_COLLECTION'
            && in_array($eventType, [FinancialEventType::InvoiceCashCustodyReceived->value,
                ...array_map(fn (FinancialEventType $type) => $type->value, FinancialEventType::correctedOrderDerived())], true)) {
            $orderId = DB::table('invoice_collections')->where('id', $id)->value('order_id');
        } elseif ($sourceType === 'ORDER_BILL_PAYMENT'
            && in_array($eventType, [FinancialEventType::BillPaymentCashCollected->value,
                FinancialEventType::BillPaymentServiceFeeRetained->value], true)) {
            $orderId = DB::table('order_bill_payments')->where('id', $id)->value('order_id');
        } elseif ($sourceType === 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER'
            && $eventType === FinancialEventType::BillPaymentPrincipalHandedOver->value) {
            $orderId = DB::table('order_bill_payment_principal_handovers')->where('id', $id)->value('order_id');
        } else {
            return;
        }
        if ($orderId !== null) {
            DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        }
    }

    private function normalizeMovements(Currency $currency, array $movements): array
    {
        if ($movements === []) {
            throw new FinancialLedgerException('A financial transaction requires at least one movement.');
        }

        $normalized = [];
        foreach ($movements as $movement) {
            $amount = $this->validatePlannedAmount($currency, $movement['amount'] ?? null);
            $account = $movement['account'] ?? null;
            if (! $account instanceof FinancialAccount || ! $account->exists) {
                throw new FinancialLedgerException('Every movement requires a persisted FinancialAccount.');
            }
            try {
                $direction = ($movement['direction'] ?? null) instanceof FinancialEntryDirection
                    ? $movement['direction']
                    : FinancialEntryDirection::from((string) ($movement['direction'] ?? ''));
            } catch (\Throwable) {
                throw new FinancialLedgerException('Financial movement direction or amount is invalid.');
            }
            if (isset($normalized[$account->id])) {
                throw new FinancialLedgerException('Only one movement per Account is allowed in a Transaction.');
            }
            $normalized[$account->id] = [
                'financial_account_id' => $account->id,
                'direction' => $direction->value,
                'amount' => $amount,
            ];
        }
        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    private function validateKind(FinancialTransactionKind $kind, array $movements): void
    {
        if ($kind !== FinancialTransactionKind::Transfer) {
            return;
        }

        $increase = BigDecimal::zero();
        $decrease = BigDecimal::zero();
        foreach ($movements as $movement) {
            if ($movement['direction'] === FinancialEntryDirection::Increase->value) {
                $increase = $increase->plus($movement['amount']);
            } else {
                $decrease = $decrease->plus($movement['amount']);
            }
        }
        if ($increase->isZero() || $decrease->isZero() || ! $increase->isEqualTo($decrease)) {
            throw new FinancialLedgerException('A TRANSFER must have equal INCREASE and DECREASE totals.');
        }
    }

    private function validateIdentity(string $sourceType, string|int $sourceId, string $eventType, int $sourceVersion): void
    {
        if (trim($sourceType) === '' || mb_strlen(trim($sourceType)) > 100 || (string) $sourceId === '' || mb_strlen((string) $sourceId) > 191 || trim($eventType) === '' || mb_strlen(trim($eventType)) > 100) {
            throw new FinancialLedgerException('Financial source identity is invalid.');
        }
        if ($sourceVersion < 1) {
            throw new FinancialLedgerException('Financial source version must be positive.');
        }
    }

    private function validateCurrency(Currency $currency): void
    {
        if (! $currency->exists || $currency->decimal_places < 0 || $currency->decimal_places > 4) {
            throw new FinancialLedgerException('Currency decimal precision must be between 0 and 4.');
        }
    }

    private function payloadHash(
        FinancialTransactionKind $kind,
        Currency $currency,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        int $sourceVersion,
        CarbonInterface $occurredAt,
        array $normalizedMovements,
        ?Branch $branch,
        ?User $recordedBy,
        ?FinancialTransaction $reversalOf,
        ?string $reason,
        ?string $notes,
    ): string {
        $payload = [
            'transaction_kind' => $kind->value,
            'currency_id' => $currency->id,
            'branch_id' => $branch?->id,
            'source_type' => trim($sourceType),
            'source_id' => (string) $sourceId,
            'event_type' => trim($eventType),
            'source_version' => $sourceVersion,
            'occurred_at' => CarbonImmutable::instance($occurredAt)->utc()->format('Y-m-d\TH:i:s.uP'),
            'recorded_by_user_id' => $recordedBy?->id,
            'reversal_of_transaction_id' => $reversalOf?->id,
            'reason' => $reason,
            'notes' => $notes,
            'movements' => $normalizedMovements,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
