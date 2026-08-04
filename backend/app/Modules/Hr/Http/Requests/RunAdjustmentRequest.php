<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An off-cycle adjustment run (FIN-010).
 *
 * Deliberately NOT extending RunPayrollRequest. That request enforces `isAlignedPeriod` — the
 * dates must be a real pay period for the frequency — which is exactly the constraint that made
 * corrections impossible. An adjustment's window is when it was ISSUED, not a period it prices,
 * so it is free-form.
 *
 * What is enforced instead is the part a regular run gets from the calendar and this one cannot:
 * an explicit employee list, a stated amount per employee, and a reason. The amounts come from a
 * person rather than from the engine, so the record of who and why is the only control there is.
 */
class RunAdjustmentRequest extends FormRequest
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
            'cycle_start' => ['required', 'date'],
            'cycle_end' => ['required', 'date', 'after_or_equal:cycle_start'],

            // Mandatory, and long enough to name the period being corrected and why. An adjustment
            // with no reason is indistinguishable from an error once anyone has forgotten it.
            'reason' => ['required', 'string', 'min:5', 'max:500'],

            // At least one line: a run that pays nobody is a mistake, not an empty batch.
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            // Positive only. A negative adjustment is a CLAWBACK — recovering money already paid —
            // which needs its own consent rules under Art. 113 and is not this feature.
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
            'reason.required' => 'An adjustment run must record why it is being paid.',
            'lines.required' => 'Add at least one employee and amount to adjust.',
            'lines.*.amount.min' => 'An adjustment must be a positive amount. Recovering an overpayment is a separate process.',
        ];
    }
}
