<?php

namespace App\Services;

use App\Models\Captain;
use App\Models\SystemSetting;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeliveryPricingSettings
{
    public const CAPTAIN_SHARE_KEY = 'CAPTAIN_DELIVERY_SHARE_PERCENT';

    public const OVERRIDE_THRESHOLD_KEY = 'DELIVERY_FEE_OVERRIDE_WITHOUT_APPROVAL_PERCENT';

    public function captainSharePercent(): ?BigDecimal
    {
        $value = SystemSetting::query()->where('key', self::CAPTAIN_SHARE_KEY)->value('value');

        return $value === null ? null : BigDecimal::of($value);
    }

    public function overrideThresholdPercent(): BigDecimal
    {
        return BigDecimal::of(SystemSetting::query()->where('key', self::OVERRIDE_THRESHOLD_KEY)->value('value') ?? '0');
    }

    public function update(User $actor, string $share, string $threshold): void
    {
        Gate::forUser($actor)->authorize('system_settings.update');
        foreach (['captain_share_percent' => $share, 'override_threshold_percent' => $threshold] as $field => $value) {
            $decimal = BigDecimal::of($value);
            if ($decimal->isLessThan(0) || $decimal->isGreaterThan(100)) {
                throw ValidationException::withMessages([$field => __('domain.percentage_must_be_between_0_and_100')]);
            }
        }
        DB::transaction(function () use ($actor, $share, $threshold): void {
            app(CaptainFuelAllowanceService::class)->lockConfiguration();
            // Historical migration-state fixtures have no fuel configuration yet.
            $maximumFuel = Schema::hasColumn('captains', 'fuel_allowance_percentage')
                ? (Captain::query()->max('fuel_allowance_percentage') ?? '0') : '0';
            if (BigDecimal::of($share)->plus($maximumFuel)->isGreaterThan(100)) {
                throw ValidationException::withMessages(['captain_share_percent' => __('captains.fuel_base_exceeds_share')]);
            }
            SystemSetting::query()->updateOrCreate(['key' => self::CAPTAIN_SHARE_KEY], ['value' => $share, 'updated_by' => $actor->id]);
            SystemSetting::query()->updateOrCreate(['key' => self::OVERRIDE_THRESHOLD_KEY], ['value' => $threshold, 'updated_by' => $actor->id]);
        });
    }
}
