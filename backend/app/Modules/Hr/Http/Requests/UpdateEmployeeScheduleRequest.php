<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeScheduleRequest extends FormRequest
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
            'work_schedule_id' => ['nullable', 'integer', 'exists:hr_work_schedules,id'],
            // Item 11 — at least one workday. An empty array made EVERY day a rest day, so the
            // period had 0 scheduled workdays, the daily rate divided by zero → ₱0, and the
            // employee was paid the full period no matter how much they missed. Null (no
            // override) is still fine; an empty list is not.
            'custom_workdays' => ['nullable', 'array', 'min:1'],
            'custom_workdays.*' => ['integer', 'between:1,7'],
            'custom_start_time' => ['nullable', 'date_format:H:i'],
            'custom_end_time' => ['nullable', 'date_format:H:i'],
            // Half-day windows. Null means INHERIT, per field — the same contract as every
            // override above, so overriding an afternoon start without its end keeps the
            // template's end rather than being refused.
            'custom_am_start_time' => ['nullable', 'date_format:H:i'],
            'custom_am_end_time' => ['nullable', 'date_format:H:i'],
            'custom_pm_start_time' => ['nullable', 'date_format:H:i'],
            'custom_pm_end_time' => ['nullable', 'date_format:H:i'],
            'custom_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'custom_unpaid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'custom_paid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ];
    }
}
