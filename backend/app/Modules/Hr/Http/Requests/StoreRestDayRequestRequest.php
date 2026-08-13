<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filing a rest-day work request.
 *
 * No `work_date`: the date is the caller's Manila today, resolved server-side. This request is
 * the press of a time-in button, and a client-supplied date would let someone file for a day
 * they are not standing in — the same reason no "my X" endpoint takes an employee_id.
 */
class StoreRestDayRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; ownership derived from the token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
