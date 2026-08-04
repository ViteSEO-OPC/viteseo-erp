<?php

namespace App\Modules\Hr\Http\Requests;

/**
 * Editing an existing loan (FIN-002).
 *
 * Identical to the create request except that `status` is not accepted at all. A loan's status is
 * a consent record, not a field: `requested → approved → pending_authorization → active` is walked
 * by the dedicated service methods, and `active` — the state payroll collects from — can only be
 * written by the employee themselves through `LoanService::authorize()`, per Art. 113.
 *
 * `LoanService::update()` already refuses to write `status`. This exists so the refusal is visible
 * at the API boundary too: a client sending it gets a validation error naming the reason, rather
 * than a silent 200 whose effect it cannot see. Two layers on purpose — the service protects the
 * invariant, the request explains it.
 */
class UpdateEmployeeLoanRequest extends StoreEmployeeLoanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['status']);

        // Say so, rather than dropping it silently — a client that thinks it is activating a loan
        // should be told it is not, not left assuming it worked.
        $rules['status'] = ['prohibited'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'A loan’s status cannot be changed by editing it. '
                . 'Use the approve, authorize, cancel or reopen actions — only the employee can '
                . 'authorize a deduction from their own pay.',
        ];
    }
}
