<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Used for both create and update (schedules have no unique field).
class StoreWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'workdays' => ['required', 'array', 'min:1'],
            'workdays.*' => ['integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'unpaid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'paid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            // REQUIRED, no longer optional. A blank half-day window is not a neutral "unset" —
            // it is the state in which an approved half day cannot be judged at all: lateness,
            // undertime and the early-out fraction all measure against these, and with nothing
            // here the platform has to decline to judge (see HalfDayWindowService::windowFor).
            // The split cannot be computed from the shift either — the schedule stores the break's
            // length but never when it falls. So it is asked for once, here, where someone knows.
            'am_start_time' => ['required', 'date_format:H:i'],
            'am_end_time' => ['required', 'date_format:H:i'],
            'pm_start_time' => ['required', 'date_format:H:i'],
            'pm_end_time' => ['required', 'date_format:H:i'],
            'is_default' => ['boolean'],
        ];
    }
}
