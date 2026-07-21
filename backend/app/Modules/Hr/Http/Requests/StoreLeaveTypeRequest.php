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
            'days_per_year' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_paid' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'max:20'],
            'allow_backdated' => ['sometimes', 'boolean'],
            // Only meaningful when backdating is allowed; NULL = no limit.
            'backdate_limit_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
