<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Support\PayslipLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A correction to a line that is ALREADY on a payslip.
 *
 * NO KIND HERE, and that is the difference from AmendPayrollRunRequest. That request had to ask
 * whether a correction was extra pay, reversed pay or a deduction, because a line appended to the
 * bottom of a document carries no clue about what it is. A correction to `late` or to `sss` does:
 * the line's own class decides what moves and what happens to the tax, so the question is not
 * asked and cannot be answered wrongly.
 *
 * NO EMPLOYEE EITHER — the payslip comes from the route, so a correction cannot be pointed at the
 * wrong person, at a period that was never run, or at an employee this period does not pay.
 *
 * THE AMOUNT IS ALWAYS A POSITIVE MAGNITUDE, on either side of the document. HR states what the
 * line IS — "the late charge is ₱300" — which is how a person says it, and which leaves no way to
 * type a deduction that quietly pays.
 */
class CorrectPayslipLineRequest extends FormRequest
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
            // WHICH LINE — a key from PayslipLines. Whether this payslip actually HAS that line is
            // a question about the payslip, answered by the service against the figures it holds.
            'target_key' => ['required', 'string', 'max:100'],

            // WHAT THE LINE IS. Optional, because a correction may be to the WORDING alone: the
            // figure was right and what it was called was not.
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],

            // What the line should be called. Display only — the line keeps its key, so every
            // register, remittance and BIR filing still groups by what the line IS.
            'label' => ['nullable', 'string', 'max:100'],

            // Mandatory, and long enough to say what was wrong. A figure that overrules the payroll
            // engine with no stated reason is indistinguishable from an error once anyone has
            // forgotten it — and this one is printed on a legally consequential document.
            'reason' => ['required', 'string', 'min:5', 'max:500'],

            // When the correction was issued. Defaults to today in Manila, which is almost always
            // right; free-form because a March correction is genuinely issued in August.
            'issued_on' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! PayslipLines::exists((string) $this->input('target_key'))) {
                $validator->errors()->add('target_key', 'That is not a line on a payslip.');
            }

            // A correction that changes neither the figure nor the wording is not a correction. It
            // would still write a ledger row and an audit entry, both saying nothing happened.
            if ($this->input('amount') === null && trim((string) $this->input('label')) === '') {
                $validator->errors()->add('amount', 'Enter the corrected amount, a new label for the line, or both.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A correction must record what was wrong with this line.',
            'amount.min' => 'Enter the amount as a positive figure — which side of the payslip it falls on comes from the line itself.',
        ];
    }
}
