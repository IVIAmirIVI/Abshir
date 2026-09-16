<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryPricingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('system_settings.update');
    }

    public function rules(): array
    {
        return ['captain_share_percent' => ['required', 'decimal:0,4', 'between:0,100'], 'override_threshold_percent' => ['required', 'decimal:0,4', 'between:0,100']];
    }
}
