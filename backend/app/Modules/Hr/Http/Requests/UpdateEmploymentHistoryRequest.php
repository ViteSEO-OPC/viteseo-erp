<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmploymentHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a role on the employment timeline. Partial: only the keys sent are written, so a
 * dialog can fix a typo'd title without restating the dates.
 *
 * `end_date` uses `present` rather than `sometimes` because sending it as null is meaningful here —
 * it reopens the role as the current one — and `sometimes` cannot tell "sent as null" from "absent".
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
