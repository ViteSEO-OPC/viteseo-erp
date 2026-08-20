<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\EmployeeLoan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An employee asking to borrow. */
class RequestLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any authenticated employee may ask; the service checks the policy's rule
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'benefit_program_id' => ['required', 'integer', 'exists:hr_benefit_programs,id'],
            'type' => ['sometimes', Rule::in(EmployeeLoan::TYPES)],
            'principal' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'per_period_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            /** diminishing-balance interest, a one-off fee, and collection order. */
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['sometimes', 'in:annual,monthly'],
            'fees' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            // Lower runs first when net pay cannot cover every obligation.
            'priority' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'start_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $principal = $this->input('principal');
            $perPeriod = $this->input('per_period_amount');

            // Same guard as the HR form: an instalment bigger than the loan is a typo, not a plan.
            if (is_numeric($principal) && is_numeric($perPeriod) && (float) $perPeriod > (float) $principal) {
                $v->errors()->add(
                    'per_period_amount',
                    'The per-period amount cannot be more than the total you are borrowing.',
                );
            }
        });
    }
}
