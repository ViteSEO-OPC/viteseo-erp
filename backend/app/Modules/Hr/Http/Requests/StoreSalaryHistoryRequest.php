<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\SalaryHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adding a compensation entry by hand. */
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
