<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TemporaryAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $captain = $this->route('captain');

        return $captain && $this->user()->can('captain_branches.assign') && $this->user()->canAccessCaptain($captain);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'captain_id' => ['prohibited'],
            'assignment_type' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'ended_by_user_id' => ['prohibited'],
        ];
    }
}
