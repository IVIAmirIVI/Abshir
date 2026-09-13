<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TerminateCaptainRequest extends FormRequest
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
            'ended_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
