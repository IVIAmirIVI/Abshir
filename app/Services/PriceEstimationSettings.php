<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class PriceEstimationSettings
{
    public const KEY = 'PRICE_ESTIMATION_WINDOW_DAYS';

    public function windowDays(): int
    {
        $stored = SystemSetting::query()->where('key', self::KEY)->value('value');
        $days = filter_var($stored, FILTER_VALIDATE_INT) ?: (int) config('pricing.estimation_window_days', 3);

        return max(1, min(365, $days));
    }

    public function updateWindowDays(User $actor, int $days): SystemSetting
    {
        Gate::forUser($actor)->authorize('system_settings.update');
        if ($days < 1 || $days > 365) {
            throw new InvalidArgumentException('The estimation window must be between 1 and 365 days.');
        }

        return SystemSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => (string) $days, 'updated_by' => $actor->id],
        );
    }
}
