<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A correction added to an already-computed period's own payslips. */
class AmendPayrollRunRequest extends FormRequest
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
            // Mandatory, and long enough to say what is being corrected and why. A correction with
            // no reason is indistinguishable from an error once anyone has forgotten it.
            'reason' => ['required', 'string', 'min:5', 'max:500'],

            // WHICH DIRECTION THIS BATCH GOES, and — for the two that take money — what it does to
            // the tax. See the kind migration: reversing pay refunds the tax withheld on it, a
            // missed deduction must not. Batch-level rather than per line because a batch already
            // shares one reason and one issue date: it is ONE correction applied to several people,
            // and a batch that both paid and clawed back would need two reasons to explain itself.
            'kind' => ['nullable', 'in:pay,pay_reversal,deduction'],

            // WHEN THE CORRECTION IS ISSUED — the date shown beside the line on the payslip.
            // Optional, defaulting to today in Manila, which is almost always right. Free-form
            // rather than pinned to the period: a March correction is genuinely issued in August.
            'issued_on' => ['nullable', 'date'],

            // At least one line: a correction that changes nobody is a mistake, not an empty batch.
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            // ALWAYS A POSITIVE MAGNITUDE. `kind` carries the direction, so a batch cannot be a
            // deduction and still have a line that pays — which is what typing signs per line
            // allowed, and it is the one mistake here that would move money the wrong way.
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'An amendment must record what it is correcting and why.',
            'lines.required' => 'Add at least one employee and amount to correct.',
            'lines.*.amount.min' => 'Enter the amount as a positive figure — whether it is paid or deducted comes from the type of correction.',
            'kind.in' => 'A correction is either additional pay, a reversal of pay, or a deduction.',
        ];
    }
}
