<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveTypeRequest extends FormRequest
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
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('hr_leave_types', 'code')->ignore($this->route('leaveType')?->id),
            ],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'max:20'],
        ];
    }
}
