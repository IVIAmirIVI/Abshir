<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RehireCaptainRequest extends FormRequest
{
    public function authorize(): bool
    {
        $captain = $this->route('captain');

        return $captain && $this->user()->can('captains.update') && $this->user()->canAccessCaptain($captain);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'started_at' => ['required', 'date'],
            'reactivate_account' => ['sometimes', 'boolean'],
        ];
    }
}
