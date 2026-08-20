<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Raise the named employees to the salary that clears the floor. */
class ApplyMinimumWageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route gated by role:hr_officer
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:hr_employees,id'],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            // Mirrors the employee-edit guard: the date may reach into completed runs, and that
            // has to be confirmed rather than assumed (EmployeeService::assertSalaryDateIsSafe).
            'acknowledge_repricing' => ['sometimes', 'boolean'],
        ];
    }
}
