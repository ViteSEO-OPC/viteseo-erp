<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ending someone's employment (MF-18).
 *
 * `separation_date` IS REQUIRED AND HAS NO DEFAULT. It is the employee's last working day, and every
 * consequence turns on it: the final regular cycle is prorated against it, leave accrual stops at that
 * month, and `runFinalPay()` refuses to run without it. Defaulting it to today would silently invent a
 * last day for a resignation processed a week late — which is a payroll figure, not a formality.
 *
 * `separation_type` is limited to the two causes the attrition report already groups by. It is
 * deliberately NOT the tax question: whether separation pay is exempt turns on whether the cause was
 * beyond the employee's control (NIRC §32(B)(6)(b)), which HR answers per case on the final-pay form
 * (FIN-014's `separation_pay_taxable`), not by picking from a dropdown here.
 */
class SeparateEmployeeRequest extends FormRequest
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
        return [
            'separation_type' => ['required', Rule::in(EmployeeService::SEPARATION_TYPES)],
            // Backdating is allowed on purpose: separations are routinely processed after the fact.
            // Future-dating is allowed too — a filed resignation with a known last day.
            'separation_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
