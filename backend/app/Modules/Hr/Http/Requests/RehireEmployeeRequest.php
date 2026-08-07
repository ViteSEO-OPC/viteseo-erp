<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking someone back as a new employment relationship (MF-18).
 *
 * `hire_date` IS REQUIRED, because that is the whole difference between this and reinstatement. Rehire
 * restarts tenure from a date somebody has to state; reinstatement says the separation never should
 * have happened and needs no date at all. A rehire form that defaulted the date would quietly pick a
 * tenure start, and tenure drives proration, leave accrual and every length-of-service question.
 *
 * The service refuses a date on or before the previous last day — that is a correction, and
 * reinstatement is the verb for it.
 */
class RehireEmployeeRequest extends FormRequest
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
            'hire_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
