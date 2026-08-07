<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FIN-030b — raise the named employees to the salary that clears the floor.
 *
 * THE IDS ARE REQUIRED AND EXPLICIT. "Fix everyone" would make the population depend on whatever
 * the screen happened to be showing when the button was pressed, and a salary change is not
 * something to apply to a list somebody did not read. Each id is a person whose pay is about to
 * change, so each one is named.
 *
 * `effective_date` is the wage order's date, not today: the whole point of an effective-dated
 * salary history (FIN-007) is that a raise mandated from 1 August is recorded as having started
 * then, which is also what makes the back pay computable.
 */
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
