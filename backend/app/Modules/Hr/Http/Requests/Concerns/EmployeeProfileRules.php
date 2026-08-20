<?php

namespace App\Modules\Hr\Http\Requests\Concerns;

use App\Modules\Hr\Services\EmployeeService;
use Closure;
use Illuminate\Validation\Rule;

trait EmployeeProfileRules
{
    /**
     * A permissive phone shape: digits plus the separators people actually type
     * (+63 917 555 0142, (02) 8123-4567). Deliberately not a locale-specific mask.
     */
    private const PHONE_REGEX = '/^[0-9+()\-.\s]{7,}$/';

    /**
     * Validation rules for the expanded personal record — shared by the store and
     * update employee requests. Everything here is optional.
     *
     * @return array<string, mixed>
     */
    protected function profileRules(): array
    {
        return [
            'employment_type' => ['nullable', 'string', 'max:100'],
            // Management staff: fixed salary + exempt from attendance.
            'is_management' => ['sometimes', 'boolean'],
            'work_location' => ['nullable', 'string', 'max:255'],
            'employee_role' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(EmployeeService::GENDERS)],
            'civil_status' => ['nullable', Rule::in(EmployeeService::CIVIL_STATUSES)],
            'nationality' => ['nullable', 'string', 'max:100'],
            // Nobody is born tomorrow, and a date before 1900 is a typo.
            'date_of_birth' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            // `address` = home address; `current_address` = where they live now.
            'phone' => ['nullable', 'string', 'max:50', 'regex:'.self::PHONE_REGEX],
            'address' => ['nullable', 'string', 'max:500'],
            'current_address' => ['nullable', 'string', 'max:500'],
            'mothers_maiden_name' => ['nullable', 'string', 'max:255'],
            'fathers_name' => ['nullable', 'string', 'max:255'],
            // One entry per named sibling. `number_of_siblings` is captured separately
            // because an employee may have more siblings than they choose to name.
            'siblings' => ['nullable', 'array', 'max:'.EmployeeService::MAX_SIBLINGS],
            'siblings.*.name' => ['required', 'string', 'max:255'],
            'siblings.*.gender' => ['nullable', Rule::in(EmployeeService::GENDERS)],
            'number_of_siblings' => [
                'nullable', 'integer', 'min:0', 'max:50', $this->siblingCountAtLeastNamed(),
            ],
            'high_school' => ['nullable', 'string', 'max:255'],
            'college_course' => ['nullable', 'string', 'max:255'],
            'university' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50', 'regex:'.self::PHONE_REGEX],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'height' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'string', 'max:50'],
            'health_condition' => ['nullable', 'string', 'max:500'],
            // Compensation
            'pay_frequency' => ['nullable', Rule::in(EmployeeService::PAY_FREQUENCIES)],
            'currency' => ['nullable', 'string', 'size:3'],
            'bank_account_type' => ['nullable', Rule::in(EmployeeService::BANK_ACCOUNT_TYPES)],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            // No per-employee statutory amounts. They overrode the EMPLOYEE side of a
            // contribution while the employer side kept following the brackets, so one payslip
            // could carry a pair that exists on no SSS table. Enrolment is the supported way to
            // say someone does not pay one.
            // No allowance rules. An allowance is a programme in Company → Benefits,
            // and three fixed columns could never express a catalogue that grows and shrinks.
            // Government membership numbers for the payslip header. Free-form strings on
            // purpose: agencies format these inconsistently and a strict mask would reject
            // legitimate older numbers.
            'tin' => ['nullable', 'string', 'max:32'],
            'sss_number' => ['nullable', 'string', 'max:32'],
            'philhealth_number' => ['nullable', 'string', 'max:32'],
            'pagibig_number' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * The stated sibling count can exceed the named entries (you needn't name them
     * all), but it can never be fewer than the names actually listed.
     */
    private function siblingCountAtLeastNamed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $named = count(array_filter(
                (array) $this->input('siblings', []),
                fn ($sibling) => trim((string) ($sibling['name'] ?? '')) !== '',
            ));

            if ($value !== null && (int) $value < $named) {
                $fail($named === 1
                    ? 'One sibling is listed, so the number of siblings cannot be 0.'
                    : "There are {$named} siblings listed, so the number of siblings cannot be lower than {$named}.");
            }
        };
    }
}
