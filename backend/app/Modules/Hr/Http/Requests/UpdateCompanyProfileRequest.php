<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:hr_officer|super_admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required on PATCH too: this is the name every payslip prints, so there is no
            // valid state where it is blank.
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            // One line, as it prints on the letterhead.
            'address' => ['nullable', 'string', 'max:500'],
            'tin' => ['nullable', 'string', 'max:32'],
            'sss_employer_number' => ['nullable', 'string', 'max:32'],
            'philhealth_employer_number' => ['nullable', 'string', 'max:32'],
            'pagibig_employer_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'legal_name.required' => 'The company name is printed on every payslip — it cannot be blank.',
        ];
    }
}
