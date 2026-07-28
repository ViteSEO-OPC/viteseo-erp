<?php

namespace App\Modules\Hr\Http\Requests\Concerns;

trait EmployeeProfileRules
{
    /**
     * Validation rules for the expanded personal record — shared by the store and
     * update employee requests. Everything here is optional.
     *
     * @return array<string, mixed>
     */
    protected static function profileRules(): array
    {
        return [
            'employment_type' => ['nullable', 'string', 'max:100'],
            // Management staff: fixed salary + exempt from attendance.
            'is_management' => ['sometimes', 'boolean'],
            'work_location' => ['nullable', 'string', 'max:255'],
            'employee_role' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'civil_status' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            // `address` = home address; `current_address` = where they live now.
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'current_address' => ['nullable', 'string', 'max:500'],
            'mothers_maiden_name' => ['nullable', 'string', 'max:255'],
            'fathers_name' => ['nullable', 'string', 'max:255'],
            'siblings_name' => ['nullable', 'string', 'max:500'],
            'high_school' => ['nullable', 'string', 'max:255'],
            'college_course' => ['nullable', 'string', 'max:255'],
            'university' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'height' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'string', 'max:50'],
            'health_condition' => ['nullable', 'string', 'max:500'],
            // Compensation
            'pay_frequency' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'max:8'],
            'bank_account_type' => ['nullable', 'string', 'max:50'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'sss_contribution' => ['nullable', 'numeric', 'min:0'],
            'pagibig_contribution' => ['nullable', 'numeric', 'min:0'],
            'philhealth_contribution' => ['nullable', 'numeric', 'min:0'],
            'food_allowance' => ['nullable', 'numeric', 'min:0'],
            'transportation_allowance' => ['nullable', 'numeric', 'min:0'],
            'load_allowance' => ['nullable', 'numeric', 'min:0'],
            // Government membership numbers for the payslip header. Free-form strings on
            // purpose: agencies format these inconsistently and a strict mask would reject
            // legitimate older numbers.
            'tin' => ['nullable', 'string', 'max:32'],
            'sss_number' => ['nullable', 'string', 'max:32'],
            'philhealth_number' => ['nullable', 'string', 'max:32'],
            'pagibig_number' => ['nullable', 'string', 'max:32'],
        ];
    }
}
