<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route gated by role:hr_officer
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'work_date' => ['required', 'date'],
            // Slice 16 — HR may assert the status. Omitted ⇒ derived from the times as before;
            // supplied ⇒ it wins, including over the clock and the leave table.
            'status' => ['sometimes', 'nullable', Rule::in(Attendance::HR_STATUSES)],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
