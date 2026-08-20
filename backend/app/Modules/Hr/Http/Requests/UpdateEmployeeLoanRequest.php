<?php

namespace App\Modules\Hr\Http\Requests;

/** Editing an existing loan. */
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
