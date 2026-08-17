<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HR asking an anonymous reporter to identify themselves.
 *
 * The reason is required — the reporter cannot weigh a request whose purpose is not stated —
 * but its length is not policed. A character count does not make a reason good, and a real one
 * can be short ("the payroll dates in your report contradict our records").
 */
class StoreConcernRevealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:hr_officer|super_admin on the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you need to know who filed this — the reporter sees this and decides on it.',
        ];
    }
}
