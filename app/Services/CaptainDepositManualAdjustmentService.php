<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainDepositManualAdjustment;
use App\Models\Currency;
use App\Models\User;
use App\Support\UiLabels;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CaptainDepositManualAdjustmentService
{
    public const PERMISSION = 'captain_deposit_manual_adjustments.create';

    public function __construct(
        private readonly FinancialAccountService $accounts,
        private readonly FinancialPostingService $posting,
        private readonly CaptainDepositManualAdjustmentEvidenceService $evidence,
    ) {}

    public function record(User $actor, Captain $captain, Branch $branch, Currency $currency, array $data, UploadedFile $file): CaptainDepositManualAdjustment
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        abort_unless($actor->is_active && $actor->isSuperAdmin(), 403);
        Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'direction' => ['required', 'in:INCREASE,DECREASE'],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d+)?$/D'],
            'reason' => ['required', 'string', 'regex:/\S/u'], 'occurred_at' => ['required', 'date'],
            'account_kind' => ['prohibited'], 'adjusted_by_user_id' => ['prohibited'],
            'source_type' => ['prohibited'], 'order_id' => ['prohibited'], 'invoice_id' => ['prohibited'],
        ])->validate();
        $metadata = $this->evidence->inspect($file);
        $occurredAt = CarbonImmutable::parse($data['occurred_at'])->utc();
        if ($occurredAt->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => __('domain.adjustment_time_cannot_be_in_the_future')]);
        }
        $path = null;
        try {
            $adjustment = DB::transaction(function () use ($actor, $captain, $branch, $currency, $data, $file, $metadata, $occurredAt, &$path): CaptainDepositManualAdjustment {
                if (! DB::scalar("SELECT to_regprocedure('captain_manual_deposits_active()') IS NOT NULL")
                    || ! DB::scalar('SELECT captain_manual_deposits_active()')) {
                    throw ValidationException::withMessages(['adjustment' => __('domain.manual_deposit_adjustments_are_not_activated')]);
                }
                // No Order lock: Currency precedes the existing globally ordered account locks.
                $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
                try {
                    $amount = $this->posting->validatePlannedAmount($currency, $data['amount']);
                } catch (FinancialLedgerException $exception) {
                    throw ValidationException::withMessages(['amount' => UiLabels::error($exception->getMessage())]);
                }
                $facts = [
                    'captain_id' => $captain->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'adjusted_by_user_id' => $actor->id, 'direction' => $data['direction'], 'amount' => $amount,
                    'reason' => $data['reason'], 'request_key' => strtolower($data['request_key']),
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'),
                    'evidence_name' => mb_substr($metadata['name'], 0, 255), 'evidence_mime' => $metadata['mime'],
                    'evidence_size' => $metadata['size'], 'evidence_sha256' => $metadata['sha256'],
                ];
                $existing = CaptainDepositManualAdjustment::query()->where('request_key', $facts['request_key'])->lockForUpdate()->first();
                if ($existing) {
                    $this->assertSame($existing, $facts);

                    return $existing;
                }
                if (! DB::scalar('SELECT manual_deposit_branch_legitimate(?,?)', [$captain->id, $branch->id])) {
                    throw ValidationException::withMessages(['branch_id' => __('domain.captain_has_no_current_historical_assignment_or_financial_relationship_with_this_branch')]);
                }
                $account = $this->accounts->getOrCreate($this->accounts->ownerForCaptain($captain), $currency, FinancialAccountKind::CaptainDeposit);
                $path = $this->evidence->store($file, $metadata);
                $facts['evidence_path'] = $path;
                $facts['payload_hash'] = $this->hash($facts);
                $inserted = DB::table('captain_deposit_manual_adjustments')->insertOrIgnore($facts + ['created_at' => now()]);
                $source = CaptainDepositManualAdjustment::query()->where('request_key', $facts['request_key'])->lockForUpdate()->sole();
                if ($inserted === 0) {
                    unset($facts['evidence_path'], $facts['payload_hash']);
                    $this->assertSame($source, $facts);

                    return $source;
                }
                $event = $source->direction === FinancialEntryDirection::Increase
                    ? FinancialEventType::CaptainDepositManualAdjustmentIncrease : FinancialEventType::CaptainDepositManualAdjustmentDecrease;
                $this->posting->postEvent($currency, 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT', $source->id, $event->value, 1,
                    $occurredAt, [['account' => $account, 'direction' => $source->direction, 'amount' => $amount]],
                    $branch, $actor, $source->reason, $source->payload_hash);
                DB::select('SELECT validate_manual_deposit(?)', [$source->id]);

                return $source;
            });

            return $adjustment;
        } finally {
            if ($path && (! isset($adjustment) || $adjustment->evidence_path !== $path)) {
                $this->evidence->delete($path);
            }
        }
    }

    private function hash(array $facts): string
    {
        return DB::scalar('SELECT manual_deposit_hash(?::jsonb)', [json_encode($facts, JSON_THROW_ON_ERROR)]);
    }

    private function assertSame(CaptainDepositManualAdjustment $source, array $facts): void
    {
        $facts['evidence_path'] = $source->evidence_path;
        if (! hash_equals($source->payload_hash, $this->hash($facts))) {
            throw new FinancialIdempotencyConflictException('Manual adjustment request already has different facts or evidence.');
        }
        DB::select('SELECT validate_manual_deposit(?)', [$source->id]);
    }
}
