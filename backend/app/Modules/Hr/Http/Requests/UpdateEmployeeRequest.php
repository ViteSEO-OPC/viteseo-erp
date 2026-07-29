<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Http\Requests\Concerns\EmployeeProfileRules;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    use EmployeeProfileRules;

    public function authorize(): bool
    {
        return true; // route is gated by role:hr_officer
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Ignore this employee's own user row in the email-unique check.
        $userId = $this->route('employee')->user_id;

        // The record edits one section at a time, so every field is `sometimes`: absent
        // means "leave it alone", present means it still has to be valid. Only the
        // service writes what it was actually sent.
        return [
            // Identity (users.name is composed from the split-name fields).
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'name_extension' => ['nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'department_id' => ['sometimes', 'required', 'integer', 'exists:hr_departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:hr_employees,id', Rule::notIn([$this->route('employee')->id])],
            'job_title' => ['sometimes', 'required', 'string', 'max:255'],
            'employment_status' => ['sometimes', 'required', Rule::in(EmployeeService::STATUSES)],
            'onboarding_status' => ['nullable', Rule::in(['pending', 'complete'])],
            'hire_date' => ['sometimes', 'required', 'date'],
            'base_salary' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            // Optional note + type stored on the salary-history row when salary changes.
            'salary_change_reason' => ['nullable', 'string', 'max:255'],
            'salary_change_type' => ['nullable', Rule::in(['promotion', 'merit', 'adjustment'])],
            // Employment-history logging when the job title changes.
            'role_change_type' => ['nullable', Rule::in(['promotion', 'transfer', 'lateral', 'correction'])],
            'role_change_reason' => ['nullable', 'string', 'max:255'],
            ...$this->profileRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manager_id.not_in' => 'An employee cannot be their own manager.',
        ];
    }
}
