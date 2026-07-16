<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendancePolicyRequest extends FormRequest
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
            'consecutive_lates_before_absence' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'monthly_late_reset' => ['sometimes', 'boolean'],
            'awol_days_threshold' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'late_deduction_mode' => ['sometimes', 'in:none,per_lates'],
            'lates_per_deduction' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'task_format' => ['sometimes', 'array'],
            'task_format.require_english' => ['sometimes', 'boolean'],
            'task_format.uppercase' => ['sometimes', 'boolean'],
        ];
    }
}
