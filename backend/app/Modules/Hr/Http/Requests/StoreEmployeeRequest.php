<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Http\Requests\Concerns\EmployeeProfileRules;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
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
        return [
            // Identity (users.name is composed from the split-name fields).
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'name_extension' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Optional: the controller generates one when it is absent. Either way
            // the value comes back ONCE in the response so HR can hand it over.
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            // Job fields.
            'department_id' => ['required', 'integer', 'exists:hr_departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'job_title' => ['required', 'string', 'max:255'],
            'employment_status' => ['required', Rule::in(EmployeeService::STATUSES)],
            'hire_date' => ['required', 'date'],
            'base_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            ...$this->profileRules(),
        ];
    }
}
