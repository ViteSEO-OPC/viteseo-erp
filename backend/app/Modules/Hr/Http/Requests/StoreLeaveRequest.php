<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; ownership derived from token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', 'exists:hr_leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            // FIN-034 — a half day OF LEAVE, which is not the same thing as a `HalfDayRequest`.
            // That one is time off with no pay and no balance; this one consumes 0.5 of the leave
            // type's credits and is paid whenever the type is paid. The spec always put this toggle
            // inside the leave form (POD 1 Employee View, "Half-day option"); it was built on the
            // attendance side instead, which left "half a day of my vacation leave" unexpressible.
            'is_half_day' => ['sometimes', 'boolean'],
            // Which half is being taken off — so the OTHER half can still be priced for
            // lateness/undertime, exactly as an approved half-day request is.
            //
            // UPPERCASE, and that is load-bearing: `DeductionService::workedWindow()` compares with
            // `===` against 'AM'/'PM', so a lowercase value would silently fall through to the
            // full-day window and price the half they were legitimately off as undertime.
            'half_day_period' => ['nullable', 'required_if:is_half_day,true', 'in:AM,PM'],
        ];
    }
}
