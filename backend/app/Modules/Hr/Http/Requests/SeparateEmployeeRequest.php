<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ending someone's employment. */
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
