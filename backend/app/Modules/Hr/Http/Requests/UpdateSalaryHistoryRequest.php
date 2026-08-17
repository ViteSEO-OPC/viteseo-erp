<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\SalaryHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a compensation entry. Partial, and gated: see SalaryHistoryService for why an entry a
 * completed payroll run has priced against needs `acknowledge_repricing` before it will move.
 */
class UpdateSalaryHistoryRequest extends FormRequest
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
            'base_salary' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_date' => ['sometimes', 'required', 'date'],
            'change_type' => ['nullable', Rule::in(SalaryHistoryService::CHANGE_TYPES)],
            'reason' => ['nullable', 'string', 'max:255'],
            'acknowledge_repricing' => ['sometimes', 'boolean'],
        ];
    }
}
