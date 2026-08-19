<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Support\PayslipLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ONE LINE, SET TO ONE FIGURE, ACROSS A WHOLE PERIOD.
 *
 * The correction that is one decision rather than N identical ones: a system outage that made
 * everyone late, a charge that should not have run this cutoff. Waiving it employee by employee is
 * the same decision retyped fifteen times, and the fifteenth is where the typo lives.
 *
 * `amount` IS USUALLY ZERO, and the screen offers no other value — "set everyone's late charge to
 * ₱300" is nonsense, since each person's is a different number, while "waive everyone's late charge
 * this period" is a real instruction. The field is still a figure rather than a hardcoded waiver,
 * because the rule is the caller's to state and the API should not pretend there is only one.
 *
 * `employee_ids` MAY BE OMITTED for everyone the period pays. Payslips without the line are skipped
 * and named in the response rather than refused: half a run having no late charge is the normal
 * case, not an error.
 */
class CorrectRunLineRequest extends FormRequest
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
            'target_key' => ['required', 'string', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'label' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'issued_on' => ['nullable', 'date'],
            // Absent means everyone. An EMPTY array does not: it names nobody, and silently
            // treating that as "everyone" is the difference between correcting one payslip and
            // correcting the whole company's.
            'employee_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', 'exists:hr_employees,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! PayslipLines::exists((string) $this->input('target_key'))) {
                $validator->errors()->add('target_key', 'That is not a line on a payslip.');
            }

            if ($this->input('amount') === null && trim((string) $this->input('label')) === '') {
                $validator->errors()->add('amount', 'Enter the figure this line should be, a new label for it, or both.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A correction applied across a period must record why.',
        ];
    }
}
