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
            'custom_workdays' => ['nullable', 'array'],
            'custom_workdays.*' => ['integer', 'between:1,7'],
            'custom_start_time' => ['nullable', 'date_format:H:i'],
            'custom_end_time' => ['nullable', 'date_format:H:i'],
            'custom_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'custom_unpaid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'custom_paid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ];
    }
}
