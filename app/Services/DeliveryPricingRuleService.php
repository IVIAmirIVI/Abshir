<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Currency;
use App\Models\DeliveryPricingRule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeliveryPricingRuleService
{
    public function applicable(int $fromAreaId, int $toAreaId, int $currencyId, Carbon $at): ?DeliveryPricingRule
    {
        return DeliveryPricingRule::query()->where(['from_area_id' => $fromAreaId, 'to_area_id' => $toAreaId, 'currency_id' => $currencyId])->effectiveAt($at)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    public function create(User $actor, array $data): DeliveryPricingRule
    {
        Gate::forUser($actor)->authorize('delivery_pricing_rules.create');
        $this->activeDimensions($data);

        return DB::transaction(function () use ($actor, $data): DeliveryPricingRule {
            Area::query()->whereKey($data['from_area_id'])->lockForUpdate()->firstOrFail();
            $this->preventOverlap($data);

            return DeliveryPricingRule::query()->create([...$data, 'is_active' => true, 'created_by' => $actor->id]);
        });
    }

    public function version(User $actor, DeliveryPricingRule $old, array $data): DeliveryPricingRule
    {
        Gate::forUser($actor)->authorize('delivery_pricing_rules.update');
        $this->activeDimensions($data);

        return DB::transaction(function () use ($actor, $old, $data): DeliveryPricingRule {
            Area::query()->whereKey($data['from_area_id'])->lockForUpdate()->firstOrFail();
            $old = DeliveryPricingRule::query()->lockForUpdate()->findOrFail($old->id);
            if ((int) $data['from_area_id'] !== $old->from_area_id || (int) $data['to_area_id'] !== $old->to_area_id || (int) $data['currency_id'] !== $old->currency_id) {
                throw ValidationException::withMessages(['rule' => 'A rule version must keep the same Area pair and Currency. Create a separate rule for new dimensions.']);
            }
            $effective = Carbon::parse($data['effective_from']);
            if ($effective->lte($old->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'A new version must begin after the prior version.']);
            }
            $old->update(['effective_until' => $effective]);
            $this->preventOverlap($data, $old->id);
            $new = DeliveryPricingRule::query()->create([...$data, 'is_active' => true, 'created_by' => $actor->id]);
            $old->update(['superseded_by' => $new->id]);

            return $new;
        });
    }

    public function setActive(User $actor, DeliveryPricingRule $rule, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'delivery_pricing_rules.activate' : 'delivery_pricing_rules.deactivate');
        DB::transaction(function () use ($rule, $active): void {
            Area::query()->whereKey($rule->from_area_id)->lockForUpdate()->firstOrFail();
            $rule = DeliveryPricingRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($active) {
                $this->preventOverlap($rule->toArray(), $rule->id);
            }
            $rule->update(['is_active' => $active]);
        });
    }

    private function activeDimensions(array $data): void
    {
        $currency = Currency::query()->whereKey($data['currency_id'])->where('is_active', true)->first();
        if (! Area::query()->whereKey($data['from_area_id'])->where('is_active', true)->exists() || ! Area::query()->whereKey($data['to_area_id'])->where('is_active', true)->exists() || ! $currency) {
            throw ValidationException::withMessages(['rule' => 'New rules require active Areas and Currency.']);
        }
        if (BigDecimal::of($data['delivery_unit_fee'])->strippedOfTrailingZeros()->getScale() > $currency->decimal_places) {
            throw ValidationException::withMessages(['delivery_unit_fee' => "The fee exceeds {$currency->code} minor-unit precision."]);
        }
    }

    private function preventOverlap(array $data, ?int $ignore = null): void
    {
        $start = Carbon::parse($data['effective_from']);
        $end = empty($data['effective_until']) ? null : Carbon::parse($data['effective_until']);
        $overlap = DeliveryPricingRule::query()->lockForUpdate()->where(['from_area_id' => $data['from_area_id'], 'to_area_id' => $data['to_area_id'], 'currency_id' => $data['currency_id']])->where('is_active', true)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->where(function ($q) use ($start): void {
            $q->whereNull('effective_until')->orWhere('effective_until', '>', $start);
        })->when($end, fn ($q) => $q->where('effective_from', '<', $end))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['effective_from' => 'This Area pair and Currency already has an overlapping effective rule.']);
        }
    }
}
