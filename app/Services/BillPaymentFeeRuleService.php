<?php

namespace App\Services;

use App\Models\BillPaymentFeeRule;
use App\Models\Currency;
use App\Models\OrderBillPaymentRequest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BillPaymentFeeRuleService
{
    public function applicable(int $currencyId, mixed $at): ?BillPaymentFeeRule
    {
        $at = Carbon::parse($at)->format('Y-m-d H:i:s.uP');

        return BillPaymentFeeRule::query()->where('currency_id', $currencyId)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByDesc('effective_from')->first();
    }

    public function create(User $actor, int $currencyId, string $fee, mixed $effectiveFrom): BillPaymentFeeRule
    {
        Gate::forUser($actor)->authorize('bill_payment_fees.manage');

        return DB::transaction(function () use ($actor, $currencyId, $fee, $effectiveFrom): BillPaymentFeeRule {
            $currency = Currency::query()->lockForUpdate()->findOrFail($currencyId);
            abort_unless($currency->is_active, 422);
            $amount = $this->validFee($currency, $fee);
            $from = Carbon::parse($effectiveFrom);
            if (BillPaymentFeeRule::query()->where('currency_id', $currencyId)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $from))->exists()) {
                throw ValidationException::withMessages(['effective_from' => __('domain.a_fee_rule_already_applies_from_this_time_version_that_rule_instead')]);
            }

            return BillPaymentFeeRule::query()->create(['currency_id' => $currencyId, 'fixed_fee' => $amount, 'effective_from' => $from, 'created_by' => $actor->id]);
        });
    }

    public function version(User $actor, BillPaymentFeeRule $rule, string $fee, mixed $effectiveFrom): BillPaymentFeeRule
    {
        Gate::forUser($actor)->authorize('bill_payment_fees.manage');

        return DB::transaction(function () use ($actor, $rule, $fee, $effectiveFrom): BillPaymentFeeRule {
            $currency = Currency::query()->lockForUpdate()->findOrFail($rule->currency_id);
            $old = BillPaymentFeeRule::query()->lockForUpdate()->findOrFail($rule->id);
            $from = Carbon::parse($effectiveFrom ?? now());
            if ($old->superseded_by || $old->effective_until || $from->lte($old->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => __('domain.version_the_latest_open_fee_rule_at_a_later_non_overlapping_time')]);
            }
            $latestSnapshot = OrderBillPaymentRequest::query()->where('fee_rule_id', $old->id)->max('requested_at');
            if ($from->lt(now()->startOfMinute()) || ($latestSnapshot && $from->lte(Carbon::parse($latestSnapshot)))) {
                throw ValidationException::withMessages(['effective_from' => __('domain.a_fee_version_cannot_change_the_effective_rule_for_an_existing_request_or_a_past_time')]);
            }
            $amount = $this->validFee($currency, $fee);
            $old->update(['effective_until' => $from]);
            $new = BillPaymentFeeRule::query()->create(['currency_id' => $currency->id, 'fixed_fee' => $amount, 'effective_from' => $from, 'created_by' => $actor->id]);
            $old->update(['superseded_by' => $new->id]);

            return $new;
        });
    }

    private function validFee(Currency $currency, string $fee): string
    {
        try {
            $amount = BigDecimal::of($fee);
            if ($amount->isGreaterThan(0) && $amount->strippedOfTrailingZeros()->getScale() <= $currency->decimal_places) {
                return (string) $amount->toScale(4);
            }
        } catch (\Throwable) {
        }
        throw ValidationException::withMessages(['fixed_fee' => __('domain.enter_a_positive_fixed_fee_within_the_currency_precision')]);
    }
}
