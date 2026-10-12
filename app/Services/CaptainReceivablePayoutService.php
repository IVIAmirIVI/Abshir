<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\FinancialOwnerType;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainReceivablePayout;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\UiLabels;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CaptainReceivablePayoutService
{
    public function __construct(
        private readonly FinancialPostingService $posting,
        private readonly FinancialBalanceQueryService $balances,
        private readonly CaptainFinancialEvidenceService $evidence,
    ) {}

    /** @param array<int, array{financial_entry_id: int|string, amount: string}> $allocations */
    public function record(User $actor, Captain $captain, Branch $branch, Currency $currency, array $data, array $allocations, UploadedFile $file): CaptainReceivablePayout
    {
        Gate::forUser($actor)->authorize('captain_receivable_payouts.create');
        abort_unless($actor->canAccessBranch($branch), 403);
        Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d+)?$/'],
            'occurred_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'in:CASH,TRANSFER'],
            'received_by_user_id' => ['nullable', 'integer', 'required_with:payment_method'],
        ])->validate();
        $method = filled($data['payment_method'] ?? null) ? $data['payment_method'] : null;
        if ($method && (int) ($data['received_by_user_id'] ?? 0) !== $captain->user_id) {
            throw ValidationException::withMessages(['received_by_user_id' => __('captain_requests.receiver_required')]);
        }
        if ($method === 'TRANSFER' && blank($data['reference'] ?? null)) {
            throw ValidationException::withMessages(['reference' => __('captain_requests.transfer_reference_required')]);
        }
        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => __('domain.select_the_exact_receivable_entries_paid')]);
        }
        $metadata = $this->evidence->inspect($file);
        $occurredAt = CarbonImmutable::parse($data['occurred_at'])->utc();
        if ($occurredAt->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => __('domain.the_payout_time_cannot_be_in_the_future')]);
        }
        $path = null;
        try {
            $payout = DB::transaction(function () use ($actor, $captain, $branch, $currency, $data, $allocations, $file, $metadata, $occurredAt, &$path): CaptainReceivablePayout {
                // Captain-only order: Currency -> global Account -> exact Branch/source reads -> source/ledger.
                $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
                try {
                    $amount = $this->posting->validatePlannedAmount($currency, $data['amount']);
                    $allocationMap = [];
                    foreach ($allocations as $allocation) {
                        $entryId = filter_var($allocation['financial_entry_id'] ?? null, FILTER_VALIDATE_INT);
                        if (! $entryId || isset($allocationMap[$entryId])) {
                            throw ValidationException::withMessages(['allocations' => __('domain.allocation_entry_identities_must_be_unique_and_valid')]);
                        }
                        $allocationMap[$entryId] = $this->posting->validatePlannedAmount($currency, $allocation['amount'] ?? null);
                    }
                } catch (FinancialLedgerException $exception) {
                    throw ValidationException::withMessages(['amount' => UiLabels::error($exception->getMessage())]);
                }
                ksort($allocationMap, SORT_NUMERIC);
                $total = array_reduce($allocationMap, fn (BigDecimal $carry, string $value): BigDecimal => $carry->plus($value), BigDecimal::zero());
                $facts = [
                    'captain_id' => $captain->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'amount' => $amount, 'paid_by' => $actor->id,
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'),
                    'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'evidence_sha256' => $metadata['sha256'],
                ];
                if (Schema::hasColumn('captain_receivable_payouts', 'payment_method')) {
                    $facts['payment_method'] = filled($data['payment_method'] ?? null) ? $data['payment_method'] : null;
                    $facts['received_by_user_id'] = $facts['payment_method'] ? (int) $data['received_by_user_id'] : null;
                } elseif (filled($data['payment_method'] ?? null)) {
                    throw ValidationException::withMessages(['payment_method' => __('captain_requests.activation_required')]);
                }
                $existing = CaptainReceivablePayout::query()->where('request_key', $data['request_key'])->lockForUpdate()->first();
                if ($existing) {
                    $this->assertSame($existing, $facts, $allocationMap);

                    return $existing;
                }
                if (! $total->isEqualTo(BigDecimal::of($amount))) {
                    throw ValidationException::withMessages(['allocations' => __('domain.allocation_total_must_equal_the_payout_amount')]);
                }
                $account = FinancialAccount::query()->where('currency_id', $currency->id)
                    ->where('account_kind', FinancialAccountKind::CaptainCompanyReceivable->value)
                    ->whereHas('owner', fn ($query) => $query->where('owner_type', FinancialOwnerType::Captain->value)->where('captain_id', $captain->id))
                    ->lockForUpdate()->first();
                if (! $account) {
                    throw ValidationException::withMessages(['amount' => __('domain.no_attributable_captain_receivable_exists_in_this_currency')]);
                }
                $branchBalance = BigDecimal::of($this->balances->captainBranchBalance($captain, $branch, $currency, FinancialAccountKind::CaptainCompanyReceivable));
                $payable = $this->balances->payableReceivableEntries($captain, $branch, $currency)->keyBy('financial_entry_id');
                $payableTotal = $payable->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus((string) $row->remaining_amount), BigDecimal::zero());
                $gated = $this->balances->gatedCorrectedReceivableEntries($captain, $branch, $currency);
                $gatedTotal = $gated->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus((string) $row->remaining_amount), BigDecimal::zero());
                $reserved = $this->balances->reservedReceivableEntries($captain, $branch, $currency);
                $reservedTotal = $reserved->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus((string) $row->remaining_amount), BigDecimal::zero());
                if (! $branchBalance->isEqualTo($payableTotal->plus($gatedTotal)->plus($reservedTotal))) {
                    throw new FinancialIdempotencyConflictException('FINANCIAL_CORRECTION_REQUIRED: Branch Receivable includes unresolved or unattributed history.');
                }
                if (! $payableTotal->isGreaterThan(0) || $payableTotal->isLessThan(BigDecimal::of($amount))
                    || $branchBalance->isLessThan(BigDecimal::of($amount))) {
                    throw ValidationException::withMessages(['amount' => __('domain.payout_exceeds_the_payable_receivable_in_this_branch_and_currency')]);
                }
                foreach ($allocationMap as $entryId => $value) {
                    $source = $payable->get($entryId);
                    if (! $source || BigDecimal::of($value)->isGreaterThan(BigDecimal::of((string) $source->remaining_amount))) {
                        throw ValidationException::withMessages(['allocations' => __('domain.a_selected_receivable_entry_is_unavailable_or_already_paid')]);
                    }
                    if (in_array($source->event_type, ['CAPTAIN_BONUS_DUE', 'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE'], true)
                        && empty($facts['payment_method'])) {
                        throw ValidationException::withMessages(['payment_method' => __('captain_requests.method_required')]);
                    }
                }
                $path = $this->evidence->store($file, $metadata);
                $inserted = DB::table('captain_receivable_payouts')->insertOrIgnore($facts + [
                    'request_key' => $data['request_key'], 'evidence_path' => $path,
                    'evidence_name' => mb_substr($metadata['name'], 0, 255),
                    'evidence_mime' => $metadata['mime'], 'evidence_size' => $metadata['size'], 'created_at' => now(),
                ]);
                $payout = CaptainReceivablePayout::query()->where('request_key', $data['request_key'])->lockForUpdate()->sole();
                if ($inserted === 0) {
                    $this->assertSame($payout, $facts, $allocationMap);

                    return $payout;
                }
                foreach ($allocationMap as $entryId => $value) {
                    DB::table('captain_receivable_payout_allocations')->insert([
                        'captain_receivable_payout_id' => $payout->id,
                        'financial_entry_id' => $entryId, 'amount' => $value, 'created_at' => now(),
                    ]);
                }
                $this->posting->postEvent($currency, 'CAPTAIN_RECEIVABLE_PAYOUT', $payout->id,
                    FinancialEventType::CaptainReceivablePaid->value, 1, $occurredAt,
                    [['account' => $account, 'direction' => FinancialEntryDirection::Decrease, 'amount' => $amount]],
                    $branch, $actor, null, json_encode(['source' => $facts, 'allocations' => $allocationMap], JSON_THROW_ON_ERROR));

                return $payout;
            });

            return $payout;
        } finally {
            if ($path && (! isset($payout) || $payout->evidence_path !== $path)) {
                $this->evidence->delete($path);
            }
        }
    }

    private function assertSame(CaptainReceivablePayout $payout, array $facts, array $allocations): void
    {
        foreach ($facts as $key => $value) {
            $existing = $key === 'occurred_at' ? $payout->occurred_at->utc()->format('Y-m-d H:i:s.uP') : (string) $payout->{$key};
            if ($existing !== (string) $value) {
                throw new FinancialIdempotencyConflictException('Payout request identity already has different facts or evidence.');
            }
        }
        $existingAllocations = $payout->allocations()->get()->mapWithKeys(fn ($row) => [$row->financial_entry_id => (string) BigDecimal::of($row->amount)->toScale(4)])->all();
        ksort($existingAllocations, SORT_NUMERIC);
        if ($existingAllocations !== $allocations) {
            throw new FinancialIdempotencyConflictException('Payout request identity already has different Receivable allocations.');
        }
    }
}
