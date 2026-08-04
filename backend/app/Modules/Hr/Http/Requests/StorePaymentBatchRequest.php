<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\PaymentBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise the payment batch for a completed run (FIN-015).
 *
 * Almost nothing is supplied by the caller on purpose. WHICH payslips get paid, and for how much,
 * is derived from the run — if the amounts were typed they would be a second, unreconciled source
 * of truth for what payroll owes, which is the class of defect this whole slice exists to close.
 * The only real decision is HOW the money leaves.
 */
class StorePaymentBatchRequest extends FormRequest
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
            'payroll_run_id' => ['required', 'integer', 'exists:hr_payroll_runs,id'],

            // One method per batch: a bank file is generated for one payment rail at a time, and
            // mixing cash into a transfer file is how a cash payment silently becomes a transfer.
            'method' => ['required', Rule::in(PaymentBatch::METHODS)],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'Choose how this batch is paid: bank transfer, cash or check.',
        ];
    }
}
