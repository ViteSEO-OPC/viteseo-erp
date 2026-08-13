<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmploymentHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a role to the employment timeline by hand — a stint nobody recorded at the time.
 *
 * Dates are unconstrained in both directions on purpose: this exists to enter history, and history
 * is by definition in the past. A future-dated role is a scheduled transfer, which is also legitimate.
 * The ordering rule that does matter (end after start) is enforced in the service, where the update
 * path can apply it to a partial payload too.
 */
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
