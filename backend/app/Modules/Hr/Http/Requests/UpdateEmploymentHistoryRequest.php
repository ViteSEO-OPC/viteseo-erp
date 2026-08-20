<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmploymentHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a role on the employment timeline. Partial: only the keys sent are written, so a
 * dialog can fix a typo'd title without restating the dates.
 */
class UpdateEmploymentHistoryRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['present', 'nullable', 'date'],
            'status' => ['nullable', Rule::in(EmploymentHistoryService::CLOSED_STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
