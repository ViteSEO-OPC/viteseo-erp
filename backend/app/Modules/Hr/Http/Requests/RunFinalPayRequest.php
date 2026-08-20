<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Final pay for one leaver. */
class RunFinalPayRequest extends FormRequest
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
            // Zero is meaningful — a just-cause dismissal carries no separation pay, and recording
            // that explicitly is different from leaving the field out.
            'separation_pay' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            // Default false = exempt, which matches involuntary separation (NIRC §32(B)(6)(b)).
            // HR sets it when the cause was within the employee's control.
            'separation_pay_taxable' => ['sometimes', 'boolean'],
            'unused_leave_days' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:400'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Record why this final pay is being issued — the separation type and any agreement it reflects.',
        ];
    }
}
