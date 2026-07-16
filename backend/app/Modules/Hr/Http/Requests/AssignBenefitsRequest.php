<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignBenefitsRequest extends FormRequest
{
    // Authorization handled by route middleware (auth:sanctum + role:hr_officer).
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:hr_employees,id'],
            'allowance_ids' => ['sometimes', 'array'],
            'allowance_ids.*' => ['integer', 'exists:hr_benefit_programs,id'],
            'hmo' => ['sometimes', 'nullable', 'array'],
            'hmo.program_id' => ['required_with:hmo', 'integer', 'exists:hr_benefit_programs,id'],
            'hmo.tiers' => ['required_with:hmo', 'array'],
        ];
    }
}
