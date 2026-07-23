<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    // Authorization is handled by route middleware (auth:sanctum + role:hr_officer).
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
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:hr_departments,code'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'head_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            // Roles/positions within this department (e.g. Business Developer).
            'positions' => ['sometimes', 'array'],
            'positions.*' => ['string', 'max:255', 'distinct'],
            // Employees assigned to a role in this department. Assigning moves the
            // employee into this department and sets their role.
            'assignments' => ['sometimes', 'array'],
            'assignments.*.employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'assignments.*.role' => ['required', 'string', 'max:255'],
        ];
    }
}
