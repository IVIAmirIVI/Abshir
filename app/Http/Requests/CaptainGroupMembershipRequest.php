<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CaptainGroupMembershipRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $captain = $this->route('captain');

        return $captain && $this->user()->can('captain_groups.assign') && $this->user()->canAccessCaptain($captain);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'captain_group_id' => ['required', 'integer', Rule::exists('captain_groups', 'id')],
            'joined_at' => ['required', 'date'],
            'left_at' => ['nullable', 'date', 'after:joined_at'],
            'captain_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'ended_by_user_id' => ['prohibited'],
        ];
    }
}
