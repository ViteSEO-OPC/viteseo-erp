<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Taking an intern on as staff at the end of their internship. */
class ConvertInternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind auth:sanctum + the HR role group
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hireDate = $this->route('employee')?->hire_date?->toDateString();

        return [
            // Anything but 'ojt' — converting an intern into an intern is not a conversion.
            'employment_status' => [
                'required',
                Rule::in(array_diff(EmployeeService::STATUSES, ['ojt'])),
            ],
            // Backdating is allowed: a conversion is routinely recorded after the fact. But not
            // to before they joined -- that would open a salary history and a role on the
            // timeline for days they were not here at all.
            'effective_date' => array_values(array_filter([
                'required',
                'date',
                $hireDate ? "after_or_equal:{$hireDate}" : null,
            ])),
            'base_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
            // Set once HR has seen the warning about repricing a settled payroll run.
            'acknowledge_repricing' => ['nullable', 'boolean'],
        ];
    }
}
