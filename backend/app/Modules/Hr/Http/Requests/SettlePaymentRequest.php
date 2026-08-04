<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record what the bank did with one transfer (FIN-015).
 *
 * `settled` is the only outcome that means money reached the employee, so it is the only one that
 * moves a payslip to `disbursed`. The two unsuccessful outcomes require a reason, because a failed
 * transfer is a thing somebody now has to chase and "failed" on its own tells them nothing.
 */
class SettlePaymentRequest extends FormRequest
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
            'status' => ['required', Rule::in(['settled', ...Payment::UNSUCCESSFUL])],

            // The bank's own reference. Optional but strongly wanted on a settlement — it is the
            // thread back to the bank statement when someone disputes being paid.
            'reference' => ['nullable', 'string', 'max:100'],

            // When it actually cleared. Defaults to now; back-dating is normal because
            // reconciliation happens after the fact.
            'settled_at' => ['nullable', 'date', 'before_or_equal:now'],

            'failure_reason' => ['required_unless:status,settled', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'failure_reason.required_unless' => 'Say why the transfer did not go through — somebody has to act on it.',
            'settled_at.before_or_equal' => 'A transfer cannot have cleared in the future.',
        ];
    }
}
