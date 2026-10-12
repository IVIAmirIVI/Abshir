<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\ShopCommissionDraft;
use App\Models\ShopPartnership;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopCommissionDraftService
{
    private const AGREEMENT_FIELDS = ['method', 'commission_value', 'group_priority', 'effective_from', 'effective_until'];

    public function assertAccess(User $actor, Shop $shop, ShopPartnership $partnership): void
    {
        Gate::forUser($actor)->authorize('shops.view');
        Gate::forUser($actor)->authorize('shop_partnerships.view');
        abort_unless($actor->canAccessShop($shop), 403);
        abort_if($partnership->shop_id !== $shop->id, 404);
    }

    public function create(User $actor, Shop $shop, ShopPartnership $partnership, array $data): ShopCommissionDraft
    {
        $this->assertAccess($actor, $shop, $partnership);
        Gate::forUser($actor)->authorize('shop_partnerships.create');
        $this->requireSchema();
        $this->onlyDraftFields($data, ['product_id', 'product_group_id', 'currency_id', 'terms', ...self::AGREEMENT_FIELDS]);
        $agreement = $this->agreementData($data);
        $shopTarget = $agreement !== [] && ! filled($data['product_id'] ?? null) && ! filled($data['product_group_id'] ?? null);
        $data['terms'] = is_string($data['terms'] ?? null) ? trim($data['terms']) : ($data['terms'] ?? null);
        $data = Validator::make($data, [
            'product_id' => ['nullable', ...($shopTarget ? [] : ['required_without:product_group_id']), 'prohibits:product_group_id', 'integer', 'exists:products,id'],
            'product_group_id' => ['nullable', ...($shopTarget ? [] : ['required_without:product_id']), 'prohibits:product_id', 'integer', 'exists:product_groups,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'terms' => ['required', 'string', 'max:5000'],
        ])->validate();
        if (! $shopTarget) {
            Gate::forUser($actor)->authorize(filled($data['product_id'] ?? null) ? 'products.view' : 'product_groups.view');
        }
        if (filled($data['currency_id'] ?? null)) {
            Gate::forUser($actor)->authorize('currencies.view');
        }

        return DB::transaction(function () use ($actor, $shop, $partnership, $data, $agreement): ShopCommissionDraft {
            $this->lockScope($actor, $shop, $partnership);
            $product = filled($data['product_id'] ?? null) ? Product::findOrFail($data['product_id']) : null;
            $group = filled($data['product_group_id'] ?? null) ? ProductGroup::findOrFail($data['product_group_id']) : null;
            $currency = filled($data['currency_id'] ?? null) ? Currency::findOrFail($data['currency_id']) : null;
            if (($product ?? $group) && ! ($product ?? $group)->is_active || ($currency && ! $currency->is_active)) {
                throw ValidationException::withMessages(['commission' => __('commissions.inactive_reference')]);
            }
            if ($partnership->commissionDrafts()->where('version', 1)->where('product_id', $product?->id)
                ->where('product_group_id', $group?->id)->where('currency_id', $currency?->id)->exists()) {
                throw ValidationException::withMessages(['commission' => __('commissions.already_exists')]);
            }

            return $partnership->commissionDrafts()->create([
                'configuration_key' => (string) Str::uuid(), 'version' => 1,
                'product_id' => $product?->id, 'product_group_id' => $group?->id, 'currency_id' => $currency?->id,
                'target_name_snapshot' => ($product ?? $group ?? $shop)->name, 'currency_code_snapshot' => $currency?->code,
                'terms' => $data['terms'], 'created_by_user_id' => $actor->id, 'recorded_at' => now(),
                ...$agreement,
            ]);
        });
    }

    public function revise(User $actor, Shop $shop, ShopPartnership $partnership, ShopCommissionDraft|int $draft, array $data): ShopCommissionDraft
    {
        $this->assertAccess($actor, $shop, $partnership);
        Gate::forUser($actor)->authorize('shop_partnerships.update');
        $this->requireSchema();
        $draft = $partnership->commissionDrafts()->findOrFail($draft instanceof ShopCommissionDraft ? $draft->id : $draft);
        $this->onlyDraftFields($data, ['terms', 'expected_version', ...self::AGREEMENT_FIELDS]);
        $agreement = array_intersect_key($draft->getAttributes(), array_flip(self::AGREEMENT_FIELDS));
        if (array_intersect(array_keys($data), self::AGREEMENT_FIELDS)) {
            Gate::forUser($actor)->authorize('currencies.view');
            $agreement = $this->agreementData(array_replace($agreement, $data, ['currency_id' => $draft->currency_id, 'product_group_id' => $draft->product_group_id]));
        }
        $data['terms'] = is_string($data['terms'] ?? null) ? trim($data['terms']) : ($data['terms'] ?? null);
        $data = Validator::make($data, ['terms' => ['required', 'string', 'max:5000'], 'expected_version' => ['required', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $shop, $partnership, $draft, $data, $agreement): ShopCommissionDraft {
            $this->lockScope($actor, $shop, $partnership);
            $draft = $partnership->commissionDrafts()->lockForUpdate()->findOrFail($draft->id);
            if ($draft->version !== (int) $data['expected_version'] || $draft->nextVersion()->exists()) {
                throw ValidationException::withMessages(['commission' => __('commissions.stale_version')]);
            }

            return $partnership->commissionDrafts()->create([
                'configuration_key' => $draft->configuration_key, 'version' => $draft->version + 1, 'replaces_id' => $draft->id,
                'product_id' => $draft->product_id, 'product_group_id' => $draft->product_group_id, 'currency_id' => $draft->currency_id,
                'target_name_snapshot' => $draft->target_name_snapshot, 'currency_code_snapshot' => $draft->currency_code_snapshot,
                'terms' => $data['terms'], 'created_by_user_id' => $actor->id, 'recorded_at' => now(),
                ...$agreement,
            ]);
        });
    }

    private function lockScope(User $actor, Shop $shop, ShopPartnership $partnership): void
    {
        $shop = Shop::query()->lockForUpdate()->findOrFail($shop->id);
        $partnership = ShopPartnership::query()->lockForUpdate()->findOrFail($partnership->id);
        $this->assertAccess($actor, $shop, $partnership);
    }

    private function requireSchema(): void
    {
        if (! Schema::hasTable('shop_commission_drafts')) {
            throw ValidationException::withMessages(['commission' => __('commissions.activation_required')]);
        }
    }

    private function agreementData(array $data): array
    {
        if (! array_intersect(array_keys($data), self::AGREEMENT_FIELDS)) {
            return [];
        }
        if (! Schema::hasColumn('shop_commission_drafts', 'method')) {
            throw ValidationException::withMessages(['commission' => __('commissions.activation_required')]);
        }
        $values = Validator::make($data, [
            'method' => ['required', 'in:PERCENTAGE,FIXED'],
            'commission_value' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[0-9]{1,60}(\.[0-9]{1,30})?$/D', (string) $value)) {
                    $fail(__('commissions.invalid_value'));
                }
            }],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date'],
            'group_priority' => filled($data['product_group_id'] ?? null) ? ['required', 'integer', 'min:0', 'max:2147483647'] : ['prohibited'],
        ])->validate();
        $currency = Currency::findOrFail($values['currency_id']);
        $value = BigDecimal::of((string) $values['commission_value']);
        if (! $currency->is_active || ($values['method'] === 'PERCENTAGE' && $value->isGreaterThan(100))
            || ($values['method'] === 'FIXED' && ! $value->isEqualTo($value->toScale($currency->decimal_places, RoundingMode::Down)))) {
            throw ValidationException::withMessages(['commission_value' => __('commissions.invalid_value')]);
        }
        $from = Carbon::parse($values['effective_from'], 'Asia/Damascus');
        $until = filled($values['effective_until'] ?? null) ? Carbon::parse($values['effective_until'], 'Asia/Damascus') : null;
        if ($until && $until->lte($from)) {
            throw ValidationException::withMessages(['effective_until' => __('commissions.invalid_interval')]);
        }

        return [
            'method' => $values['method'], 'commission_value' => (string) $value,
            'group_priority' => $values['group_priority'] ?? null,
            'effective_from' => $from,
            'effective_until' => $until,
        ];
    }

    private function onlyDraftFields(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) {
            throw ValidationException::withMessages(['commission' => __('commissions.unsupported_fields')]);
        }
    }
}
