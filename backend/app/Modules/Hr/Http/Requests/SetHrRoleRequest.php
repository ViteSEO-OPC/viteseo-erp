<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetHrRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already gated by role:hr_officer; no extra per-record rule.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_hr_officer' => ['required', 'boolean'],
        ];
    }
}
