<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveTypeRequest extends FormRequest
{
    // Authorization handled by route middleware (auth:sanctum + role:hr_officer).
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
            'code' => ['required', 'string', 'max:50', 'unique:hr_leave_types,code'],
            // Per-status credits live in hr_leave_type_allotments; this base default
            // is optional and stored as 0 when omitted.
            'days_per_year' => ['sometimes', 'numeric', 'min:0', 'max:365'],
            // Accrual. 'none' is the default and means hand-managed exactly as before.
            'accrual_mode' => ['sometimes', 'in:none,monthly,annual'],
            'prorate_first_year' => ['sometimes', 'boolean'],
            // NULL = everything carries over; 0 = nothing does. No default figure — the cap and its
            // expiry are company policy, not law (only Art. 95's 5-day SIL is statutory).
            'carry_over_cap_days' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:365'],
            'carry_over_expiry_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'is_paid' => ['sometimes', 'boolean'],
            // Independent of `is_paid`: paid/unpaid is what payroll does with the day, unlimited
            // is whether the type draws down credits at all.
            'is_unlimited' => ['sometimes', 'boolean'],
            // A real hex colour now that the form is a picker + hex field rather than a fixed
            // dropdown. `max:20` accepted anything short, so a typo was stored and later rendered
            // as a broken swatch with nothing to explain why.
            'color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'allow_backdated' => ['sometimes', 'boolean'],
            // Only meaningful when backdating is allowed; NULL = no limit.
            'backdate_limit_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.regex' => 'The colour must be a hex code such as #4FDBD7.',
        ];
    }
}
