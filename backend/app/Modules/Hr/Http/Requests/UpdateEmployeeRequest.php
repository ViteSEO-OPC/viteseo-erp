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
            // The date the new salary actually takes effect. Optional: omitted means
            // today, which is the behaviour that existed before (every change was stamped `now`).
            // Given, it lets HR record a raise that was agreed earlier without pretending it
            // started the day it was typed in.
            'salary_effective_date' => ['sometimes', 'nullable', 'date'],
            // Explicit acknowledgement that the date reaches into finished payroll runs.
            'acknowledge_repricing' => ['sometimes', 'boolean'],
            'salary_change_reason' => ['nullable', 'string', 'max:255'],
            'salary_change_type' => ['nullable', Rule::in(['promotion', 'merit', 'adjustment'])],
            // Excluded from the minimum-wage check. The reason is REQUIRED when the flag
            // is set: an unexplained exemption is not auditable, and this is the field somebody
            // will be asked to justify.
            'minimum_wage_exempt' => ['sometimes', 'boolean'],
            'minimum_wage_exempt_reason' => [
                'nullable',
                'required_if_accepted:minimum_wage_exempt',
                'string',
                'max:255',
            ],
            // Employment-history logging when the job title changes.
            'role_change_type' => ['nullable', Rule::in(['promotion', 'transfer', 'lateral', 'correction'])],
            'role_change_reason' => ['nullable', 'string', 'max:255'],
            // Interns only (see after()). Null means the company default in Intern Allowance
            // settings, read in the unit that page calculates in.
            'allowance_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'ojt_required_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2000'],
            ...$this->profileRules(),
        ];
    }

    /**
     * An allowance and practicum hours describe an internship. Refused on anyone else, judged
     * against the status this same save leaves them with.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator): void {
                $status = $this->input('employment_status', $this->route('employee')->employment_status);
                if ($status === 'ojt') {
                    return;
                }

                foreach ([
                    'allowance_amount' => 'Only an intern has an allowance.',
                    'ojt_required_hours' => 'Only an intern has required practicum hours.',
                ] as $field => $message) {
                    if ($this->filled($field)) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
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
