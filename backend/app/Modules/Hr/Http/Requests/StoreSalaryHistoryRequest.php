<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\SalaryHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a compensation entry by hand.
 *
 * `base_salary` is the MONTHLY figure, matching `hr_employees.base_salary` and every other salary
 * input in the module — the record page annualises it for display only. Entering an annual figure
 * here would overpay by twelve, so the field is labelled monthly on the form as well.
 *
 * `acknowledge_repricing` is the second-call confirmation: the service refuses a date that falls
 * inside completed payroll runs until this comes back true. Same mechanism as the backdated raise
 * on the record itself (FIN-007).
 */
class StoreSalaryHistoryRequest extends FormRequest
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
            'base_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_date' => ['required', 'date'],
            'change_type' => ['nullable', Rule::in(SalaryHistoryService::CHANGE_TYPES)],
            'reason' => ['nullable', 'string', 'max:255'],
            'acknowledge_repricing' => ['sometimes', 'boolean'],
        ];
    }
}
