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
            // Per-status credits live in hr_leave_type_allotments; base default is optional.
            'days_per_year' => ['sometimes', 'numeric', 'min:0', 'max:365'],
            // Accrual. 'none' is the default and means hand-managed exactly as before.
            'accrual_mode' => ['sometimes', 'in:none,monthly,annual'],
            'prorate_first_year' => ['sometimes', 'boolean'],
            // NULL = everything carries over; 0 = nothing does. No default figure — the cap and its
            // expiry are company policy, not law (only Art. 95's 5-day SIL is statutory).
            'carry_over_cap_days' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:365'],
            'carry_over_expiry_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'is_paid' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'allow_backdated' => ['sometimes', 'boolean'],
            // Only meaningful when backdating is allowed; NULL = no limit.
            'backdate_limit_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
