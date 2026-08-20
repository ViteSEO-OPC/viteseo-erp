<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filing a work request — permission to work a rest day, or a suspended day HR has marked as
 * needing permission.
 *
 * No `work_date` and no `type`: the date is the caller's Manila today, resolved server-side,
 * and the type follows from that date's schedule and suspension. This request is the press of a
 * time-in button — a client-supplied date would let someone file for a day they are not
 * standing in, the same reason no "my X" endpoint takes an employee_id, and a client-supplied
 * type would let them pick the gate they would rather be judged by.
 */
class StoreWorkRequestRequest extends FormRequest
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
