<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Exceptions\FinancialLedgerException;
use App\Models\Captain;
use App\Models\CaptainBenefitRequest;
use App\Models\CaptainBenefitReview;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CaptainBenefitReviewService
{
    public function __construct(
        private readonly CaptainBenefitRequestService $requests,
        private readonly FinancialAccountService $accounts,
        private readonly FinancialPostingService $posting,
        private readonly CaptainFinancialEvidenceService $evidence,
    ) {}

    public function permission(CaptainBenefitRequest $proposal): string
    {
        return $proposal->kind === 'BONUS' ? 'captain_bonuses.approve' : 'captain_expenses.approve';
    }

    public function act(User $actor, Captain $captain, CaptainBenefitRequest $proposal, array $data): CaptainBenefitReview
    {
        $this->requests->assertRecord($actor, $captain, $proposal);
        $data = Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'action' => ['required', 'in:SUBMIT,APPROVE,REJECT,CANCEL,REVERSE'],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:action,REJECT,REVERSE'],
        ])->validate();
        Gate::forUser($actor)->authorize(in_array($data['action'], ['SUBMIT', 'CANCEL'], true) ? 'captains.update' : $this->permission($proposal));
        if (! Schema::hasTable('captain_benefit_reviews')) {
            throw ValidationException::withMessages(['proposal' => __('captain_requests.activation_required')]);
        }
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;
        $hash = hash('sha256', json_encode([$proposal->id, $actor->id, $data['action'], $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $captain, $proposal, $data, $reason, $hash): CaptainBenefitReview {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['benefit-review:'.$data['request_key']]);
            $currency = Currency::query()->lockForUpdate()->findOrFail($proposal->currency_id);
            $proposal = CaptainBenefitRequest::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->requests->assertRecord($actor, $captain, $proposal);
            $existing = CaptainBenefitReview::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => __('captain_requests.conflict')]);
                }

                return $existing;
            }
            if ($proposal->nextVersion()->exists()) {
                throw ValidationException::withMessages(['proposal' => __('captain_requests.stale')]);
            }
            $reviews = $proposal->reviews()->get()->keyBy('action');
            $action = $data['action'];
            $terminal = $reviews->has('APPROVE') || $reviews->has('REJECT') || $reviews->has('CANCEL');
            if (($action === 'SUBMIT' && $reviews->isNotEmpty())
                || (in_array($action, ['APPROVE', 'REJECT'], true) && (! $reviews->has('SUBMIT') || $terminal))
                || ($action === 'CANCEL' && $terminal)
                || ($action === 'REVERSE' && (! $reviews->has('APPROVE') || $reviews->has('REVERSE')))) {
                throw ValidationException::withMessages(['proposal' => __('captain_requests.invalid_state')]);
            }
            if ($action === 'APPROVE') {
                if (in_array($actor->id, [$proposal->created_by_user_id, $proposal->recorded_by_user_id, $reviews->get('SUBMIT')->actor_id], true)) {
                    throw ValidationException::withMessages(['proposal' => __('captain_requests.self_approval')]);
                }
                try {
                    $this->posting->validatePlannedAmount($currency, $proposal->amount);
                } catch (FinancialLedgerException) {
                    throw ValidationException::withMessages(['amount' => __('captain_requests.invalid_amount')]);
                }
                if ($proposal->kind === 'EXPENSE' && ! $proposal->evidence_path) {
                    throw ValidationException::withMessages(['evidence' => __('captain_requests.expense_evidence_required')]);
                }
                if ($proposal->evidence_path) {
                    try {
                        $bytes = $this->evidence->bytes($proposal->evidence_path, $proposal->evidence_sha256);
                        if (strlen($bytes) !== $proposal->evidence_size || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $proposal->evidence_mime) {
                            throw new \RuntimeException('Evidence metadata mismatch.');
                        }
                    } catch (\Throwable) {
                        throw ValidationException::withMessages(['evidence' => __('domain.evidence_integrity')]);
                    }
                }
            }
            $account = in_array($action, ['APPROVE', 'REVERSE'], true)
                ? $this->accounts->getOrCreate($this->accounts->ownerForCaptain($captain), $currency, FinancialAccountKind::CaptainCompanyReceivable) : null;
            $original = $action === 'REVERSE' ? FinancialTransaction::where('source_type', 'CAPTAIN_BENEFIT_APPROVAL')
                ->where('source_id', (string) $reviews->get('APPROVE')->id)->sole() : null;
            if ($original && DB::table('captain_receivable_payout_allocations as pa')->join('financial_entries as e', 'e.id', '=', 'pa.financial_entry_id')
                ->where('e.financial_transaction_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['proposal' => __('captain_requests.paid_reversal_blocked')]);
            }
            $review = $proposal->reviews()->create(['actor_id' => $actor->id, 'action' => $action,
                'reason' => $reason, 'request_key' => $data['request_key'], 'request_hash' => $hash, 'recorded_at' => now()]);
            if ($action === 'APPROVE') {
                $this->posting->postEvent($currency, 'CAPTAIN_BENEFIT_APPROVAL', $review->id,
                    $proposal->kind === 'BONUS' ? FinancialEventType::CaptainBonusDue->value : FinancialEventType::CaptainExpenseReimbursementDue->value,
                    1, $review->recorded_at,
                    [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $proposal->amount]],
                    $proposal->branch, $actor, $proposal->description);
            } elseif ($original) {
                $this->posting->reverse($original, 'CAPTAIN_BENEFIT_REVERSAL', $review->id,
                    FinancialEventType::CaptainBenefitReversed->value, 1, $review->recorded_at, $reason, $proposal->branch, $actor);
            }

            return $review;
        }, 3);
    }

    /** @return array{state: string, paid: string, remaining: string} */
    public function summary(CaptainBenefitRequest $proposal): array
    {
        $state = 'PROPOSED';
        $paid = BigDecimal::zero();
        if (Schema::hasTable('captain_benefit_reviews')) {
            $reviews = $proposal->reviews()->get()->keyBy('action');
            $state = match (true) {
                $reviews->has('REVERSE') => 'REVERSED',
                $reviews->has('CANCEL') => 'CANCELLED',
                $reviews->has('REJECT') => 'REJECTED',
                $reviews->has('APPROVE') => 'APPROVED_UNPAID',
                $reviews->has('SUBMIT') => 'PENDING',
                default => 'PROPOSED',
            };
            if ($reviews->has('APPROVE') && $state !== 'REVERSED') {
                $paid = BigDecimal::of((string) DB::table('captain_receivable_payout_allocations as pa')
                    ->join('financial_entries as e', 'e.id', '=', 'pa.financial_entry_id')
                    ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
                    ->where('t.source_type', 'CAPTAIN_BENEFIT_APPROVAL')->where('t.source_id', (string) $reviews->get('APPROVE')->id)->sum('pa.amount'));
                $state = $paid->isEqualTo($proposal->amount) ? 'FULLY_PAID' : ($paid->isGreaterThan(0) ? 'PARTIALLY_PAID' : 'APPROVED_UNPAID');
            }
        }

        return ['state' => $state, 'paid' => (string) $paid->toScale(4),
            'remaining' => in_array($state, ['APPROVED_UNPAID', 'PARTIALLY_PAID', 'FULLY_PAID'], true)
                ? (string) BigDecimal::of($proposal->amount)->minus($paid)->toScale(4) : '0.0000'];
    }
}
