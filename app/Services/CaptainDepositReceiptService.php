<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainDepositReceipt;
use App\Models\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CaptainDepositReceiptService
{
    public function __construct(
        private readonly FinancialAccountService $accounts,
        private readonly FinancialPostingService $posting,
        private readonly CaptainFinancialEvidenceService $evidence,
    ) {}

    public function record(User $actor, Captain $captain, Branch $branch, Currency $currency, array $data, UploadedFile $file): CaptainDepositReceipt
    {
        Gate::forUser($actor)->authorize('captain_deposits.create');
        abort_unless($actor->canAccessBranch($branch), 403);
        Validator::make($data, [
            'request_key' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d+)?$/'],
            'occurred_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string'],
        ])->validate();
        $metadata = $this->evidence->inspect($file);
        $occurredAt = CarbonImmutable::parse($data['occurred_at'])->utc();
        if ($occurredAt->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'The receipt time cannot be in the future.']);
        }
        $path = null;
        try {
            $receipt = DB::transaction(function () use ($actor, $captain, $branch, $currency, $data, $file, $metadata, $occurredAt, &$path): CaptainDepositReceipt {
                $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
                try {
                    $amount = $this->posting->validatePlannedAmount($currency, $data['amount']);
                } catch (FinancialLedgerException $exception) {
                    throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
                }
                $facts = [
                    'captain_id' => $captain->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'amount' => $amount, 'received_by' => $actor->id,
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'),
                    'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'evidence_sha256' => $metadata['sha256'],
                ];
                $existing = CaptainDepositReceipt::query()->where('request_key', $data['request_key'])->lockForUpdate()->first();
                if ($existing) {
                    $this->assertSame($existing, $facts);

                    return $existing;
                }
                $owner = $this->accounts->ownerForCaptain($captain);
                $account = $this->accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
                $path = $this->evidence->store($file, $metadata);
                $inserted = DB::table('captain_deposit_receipts')->insertOrIgnore($facts + [
                    'request_key' => $data['request_key'], 'evidence_path' => $path,
                    'evidence_name' => mb_substr($metadata['name'], 0, 255),
                    'evidence_mime' => $metadata['mime'], 'evidence_size' => $metadata['size'],
                    'created_at' => now(),
                ]);
                $receipt = CaptainDepositReceipt::query()->where('request_key', $data['request_key'])->lockForUpdate()->sole();
                if ($inserted === 0) {
                    $this->assertSame($receipt, $facts);

                    return $receipt;
                }
                $this->posting->postEvent($currency, 'CAPTAIN_DEPOSIT_RECEIPT', $receipt->id,
                    FinancialEventType::CaptainDepositReceived->value, 1, $occurredAt,
                    [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $amount]],
                    $branch, $actor, null, json_encode($facts, JSON_THROW_ON_ERROR));

                return $receipt;
            });

            return $receipt;
        } finally {
            if ($path && (! isset($receipt) || $receipt->evidence_path !== $path)) {
                $this->evidence->delete($path);
            }
        }
    }

    private function assertSame(CaptainDepositReceipt $receipt, array $facts): void
    {
        foreach ($facts as $key => $value) {
            $existing = $key === 'occurred_at' ? $receipt->occurred_at->utc()->format('Y-m-d H:i:s.uP') : (string) $receipt->{$key};
            if ($existing !== (string) $value) {
                throw new FinancialIdempotencyConflictException('Deposit request identity already has different facts or evidence.');
            }
        }
    }
}
