<?php

namespace App\Services;

use App\Models\BillPaymentFeeRule;
use App\Models\Currency;
use App\Models\OrderBillPaymentRequest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BillPaymentFeeRuleService
{
    /** Default configuration only: never creates or activates development rules. */
    public static function sypTiers(): array
    {
        return [
            ['from' => '0', 'until' => '300', 'type' => 'FIXED', 'value' => '10'],
            ['from' => '300', 'until' => '1000', 'type' => 'FIXED', 'value' => '20'],
            ['from' => '1000', 'until' => null, 'type' => 'PERCENTAGE', 'value' => '2'],
        ];
    }

    public function supportsTiers(): bool
    {
        return Schema::hasColumn('bill_payment_fee_rules', 'fee_tiers');
    }

    /** The immutable request binds a version; principal determines its tier at completion. */
    public function calculate(BillPaymentFeeRule $rule, Currency $currency, string $principal): string
    {
        $principal = app(FinancialPostingService::class)->validatePlannedAmount($currency, $principal);
        if ($rule->currency_id !== $currency->id) {
            throw ValidationException::withMessages(['currency_id' => __('bill_fees.currency_mismatch')]);
        }
        if (($rule->fee_mode ?? 'FIXED') === 'FIXED') {
            return $this->validFee($currency, $rule->fixed_fee);
        }
        if ($currency->code !== 'SYP') {
            throw ValidationException::withMessages(['currency_id' => __('bill_fees.invalid_tiers')]);
        }
        $amount = BigDecimal::of($principal);
        $matches = array_filter($rule->fee_tiers, fn ($tier) => $amount->isGreaterThanOrEqualTo($tier['from'])
            && ($tier['until'] === null || $amount->isLessThan($tier['until'])));
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['principal_amount' => __('bill_fees.invalid_tiers')]);
        }
        $tier = array_values($matches)[0];
        $fee = $tier['type'] === 'FIXED' ? BigDecimal::of($tier['value'])
            : $amount->multipliedBy($tier['value'])->dividedBy('500', 0, RoundingMode::HalfUp)->multipliedBy('5');

        return app(FinancialPostingService::class)->validatePlannedAmount($currency, (string) $fee);
    }

    private function configuration(Currency $currency, ?string $fee, ?array $tiers): array
    {
        if ($tiers === null) {
            return ['fixed_fee' => $this->validFee($currency, $fee ?? '')];
        }
        if (! $this->supportsTiers()) {
            throw ValidationException::withMessages(['fee_tiers' => __('bill_fees.migration_required')]);
        }
        if ($currency->code !== 'SYP' || ! array_is_list($tiers) || $tiers === []) {
            throw ValidationException::withMessages(['fee_tiers' => __('bill_fees.invalid_tiers')]);
        }
        $next = BigDecimal::of('0');
        $normalized = [];
        try {
            foreach ($tiers as $index => $tier) {
                foreach (['from', 'value'] as $key) {
                    if (! is_string($tier[$key] ?? null) || ! preg_match('/^\d+(?:\.\d{1,4})?$/D', $tier[$key])) {
                        throw new \InvalidArgumentException;
                    }
                }
                $from = BigDecimal::of($tier['from']);
                $untilRaw = $tier['until'] ?? null;
                if ($untilRaw === '') {
                    $untilRaw = null;
                }
                if ($untilRaw !== null && (! is_string($untilRaw) || ! preg_match('/^\d+(?:\.\d{1,4})?$/D', $untilRaw))) {
                    throw new \InvalidArgumentException;
                }
                $until = $untilRaw === null ? null : BigDecimal::of($untilRaw);
                $value = BigDecimal::of($tier['value']);
                if (! $from->isEqualTo($next) || ($until && ! $until->isGreaterThan($from))
                    || (($until === null) !== ($index === array_key_last($tiers)))
                    || ! in_array($tier['type'] ?? null, ['FIXED', 'PERCENTAGE'], true) || ! $value->isGreaterThan(0)) {
                    throw new \InvalidArgumentException;
                }
                if ($tier['type'] === 'FIXED') {
                    $this->validFee($currency, $tier['value']);
                }
                $normalized[] = ['from' => (string) $from, 'until' => $until === null ? null : (string) $until,
                    'type' => $tier['type'], 'value' => (string) $value];
                $next = $until;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['fee_tiers' => __('bill_fees.invalid_tiers')]);
        }

        return ['fixed_fee' => null, 'fee_mode' => 'TIERED', 'fee_tiers' => $normalized];
    }

    public function applicable(int $currencyId, mixed $at): ?BillPaymentFeeRule
    {
        $at = Carbon::parse($at)->format('Y-m-d H:i:s.uP');

        return BillPaymentFeeRule::query()->where('currency_id', $currencyId)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByDesc('effective_from')->first();
    }

    public function create(User $actor, int $currencyId, ?string $fee, mixed $effectiveFrom, ?array $tiers = null): BillPaymentFeeRule
    {
        Gate::forUser($actor)->authorize('bill_payment_fees.manage');

        return DB::transaction(function () use ($actor, $currencyId, $fee, $effectiveFrom, $tiers): BillPaymentFeeRule {
            $currency = Currency::query()->lockForUpdate()->findOrFail($currencyId);
            abort_unless($currency->is_active, 422);
            $configuration = $this->configuration($currency, $fee, $tiers);
            $from = Carbon::parse($effectiveFrom);
            if (BillPaymentFeeRule::query()->where('currency_id', $currencyId)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $from))->exists()) {
                throw ValidationException::withMessages(['effective_from' => __('domain.a_fee_rule_already_applies_from_this_time_version_that_rule_instead')]);
            }

            return BillPaymentFeeRule::query()->create(['currency_id' => $currencyId, ...$configuration, 'effective_from' => $from, 'created_by' => $actor->id]);
        });
    }

    public function version(User $actor, BillPaymentFeeRule $rule, ?string $fee, mixed $effectiveFrom, ?array $tiers = null): BillPaymentFeeRule
    {
        Gate::forUser($actor)->authorize('bill_payment_fees.manage');

        return DB::transaction(function () use ($actor, $rule, $fee, $effectiveFrom, $tiers): BillPaymentFeeRule {
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
            $configuration = $this->configuration($currency, $fee, $tiers);
            $old->update(['effective_until' => $from]);
            $new = BillPaymentFeeRule::query()->create(['currency_id' => $currency->id, ...$configuration, 'effective_from' => $from, 'created_by' => $actor->id]);
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
