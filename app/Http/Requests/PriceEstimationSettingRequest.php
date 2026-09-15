<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PriceEstimationSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('system_settings.update');
    }

    public function rules(): array
    {
        return ['estimation_window_days' => ['required', 'integer', 'between:1,365']];
    }
}
