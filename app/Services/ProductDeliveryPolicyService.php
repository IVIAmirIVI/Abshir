<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductDeliveryPolicy;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductDeliveryPolicyService
{
    /** Called inside ProductService's transaction and Product row lock. */
    public function saveLocked(User $actor, Product $product, array $data): ProductDeliveryPolicy
    {
        abort_unless($actor->isSuperAdmin(), 403);
        $values = Validator::make($data, [
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'delivery_fee' => ['required', 'decimal:0,4', 'gt:0'],
            'allocation_mode' => ['required', 'in:DEFAULT,PERCENTAGE,FIXED'],
            'captain_percentage' => ['exclude_unless:allocation_mode,PERCENTAGE', 'required', 'decimal:0,4', 'between:0,100'],
            'captain_fixed' => ['exclude_unless:allocation_mode,FIXED', 'required', 'decimal:0,4', 'gte:0'],
            'company_fixed' => ['exclude_unless:allocation_mode,FIXED', 'required', 'decimal:0,4', 'gte:0'],
        ], [], __('special.attributes'))->validate();
        $currency = Currency::findOrFail($values['currency_id']);
        if (! $currency->is_active) {
            throw ValidationException::withMessages(['special_policy' => __('special.invalid_policy')]);
        }
        foreach (['delivery_fee', 'captain_fixed', 'company_fixed'] as $key) {
            if (isset($values[$key])) {
                try {
                    BigDecimal::of($values[$key])->toScale($currency->decimal_places, RoundingMode::Unnecessary);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['special_policy' => __('special.precision')]);
                }
            }
        }
        if ($values['allocation_mode'] === 'FIXED' && ! BigDecimal::of($values['captain_fixed'])->plus($values['company_fixed'])->isEqualTo($values['delivery_fee'])) {
            throw ValidationException::withMessages(['special_policy' => __('special.fixed_sum')]);
        }

        return $product->deliveryPolicies()->create([
            ...$values, 'unit_id' => $product->default_unit_id, 'version' => ((int) $product->deliveryPolicies()->max('version')) + 1,
            'captain_percentage' => $values['allocation_mode'] === 'PERCENTAGE' ? $values['captain_percentage'] : null,
            'captain_fixed' => $values['allocation_mode'] === 'FIXED' ? $values['captain_fixed'] : null,
            'company_fixed' => $values['allocation_mode'] === 'FIXED' ? $values['company_fixed'] : null,
            'created_by' => $actor->id,
        ]);
    }
}
