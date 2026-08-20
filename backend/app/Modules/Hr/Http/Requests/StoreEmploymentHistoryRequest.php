<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmploymentHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adding a role to the employment timeline by hand — a stint nobody recorded at the time. */
class StoreEmploymentHistoryRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            // Null means "still in this role", which the service allows for exactly one row.
            'end_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(EmploymentHistoryService::CLOSED_STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
