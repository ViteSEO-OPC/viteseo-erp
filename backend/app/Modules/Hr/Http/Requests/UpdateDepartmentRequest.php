<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // {department} is route-model-bound, so grab its id to ignore itself
        // in the unique check (otherwise updating without changing code fails).
        $departmentId = $this->route('department')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hr_departments', 'code')->ignore($departmentId),
            ],
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
