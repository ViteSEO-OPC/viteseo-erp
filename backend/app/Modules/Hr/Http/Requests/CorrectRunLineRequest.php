<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Support\PayslipLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** ONE LINE, SET TO ONE FIGURE, ACROSS A WHOLE PERIOD. */
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
