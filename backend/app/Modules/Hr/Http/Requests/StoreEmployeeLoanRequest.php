<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\EmployeeLoan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeLoanRequest extends FormRequest
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
            // Slice B15 — the policy this loan is issued under. Optional: an ad-hoc arrangement
            // has no product behind it, and the service validates the fit when one is given.
            'benefit_program_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_benefit_programs,id'],
            'type' => ['sometimes', Rule::in(EmployeeLoan::TYPES)],
            'reference' => ['nullable', 'string', 'max:60'],
            'principal' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'per_period_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            /**
             * FIN-032 — diminishing-balance interest, a one-off fee, and collection order.
             *
             * The rate is capped at 100% because it is a percentage, and the period says whether it is
             * quoted per year or per month: reading "2%" as annual when the company meant monthly is a
             * twelve-fold error, so it is asked rather than assumed. All optional and all default to
             * zero — an interest-free advance is a real product, not a missing configuration.
             */
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['sometimes', 'in:annual,monthly'],
            'fees' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            // Lower runs first when net pay cannot cover every obligation.
            'priority' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'start_date' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(EmployeeLoan::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $principal = $this->input('principal');
            $perPeriod = $this->input('per_period_amount');

            // An instalment larger than the loan itself isn't wrong so much as pointless — it
            // just settles in one period. Larger than the principal is a typo worth catching.
            if (is_numeric($principal) && is_numeric($perPeriod) && (float) $perPeriod > (float) $principal) {
                $v->errors()->add(
                    'per_period_amount',
                    'The per-period amount cannot be more than the total principal.',
                );
            }
        });
    }
}
