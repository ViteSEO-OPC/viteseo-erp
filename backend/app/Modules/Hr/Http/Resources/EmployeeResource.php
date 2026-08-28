<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * opt-in, because the minimum-wage check is not free: deriving one daily rate reads
     * the employee's schedule for the current period. On a single record that is nothing; on a
     * 200-row directory it is 200 schedule lookups for a field the directory does not show.
     */
    private bool $withPayrollChecks = false;

    public function withPayrollChecks(): static
    {
        $this->withPayrollChecks = true;

        return $this;
    }

    /**
     * Opt-in the other way: the DIRECTORY projection, carrying only the fields the employee list
     * actually renders plus the ones its dropdowns filter on.
     *
     * The full record is 74 fields — every address line, every government number, the whole
     * personal history — and the directory shows about eight of them. That is roughly 1.3 KB per
     * row spent to draw a name, a job title and a department, and it puts every employee's
     * personal record in the browser of anyone who opens the list. Neither is needed to render
     * a directory.
     *
     * `is_hr_officer` is deliberately absent: computing it touches the roles relation, and
     * dropping it is what lets `EmployeeService::list()` stop eager-loading `user.roles` for the
     * whole directory. The single-employee view still carries it.
     */
    private bool $lean = false;

    public function lean(): static
    {
        $this->lean = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // What the directory renders: the card (name, photo, job title, department, the
        // former/pending badges) and the filters over it (email search, status, management).
        $summary = [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            // Browser-facing URL for the profile picture; null falls back to initials.
            'photo_url' => $this->photo_url,
            // Identity comes from the linked user (relations are eager-loaded).
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'job_title' => $this->job_title,
            'employment_status' => $this->employment_status,
            'onboarding_status' => $this->onboarding_status,
            'is_management' => (bool) $this->is_management,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'separation_date' => $this->separation_date?->format('Y-m-d'),
            'is_separated' => $this->separation_date !== null || $this->deleted_at !== null,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null,
        ];

        if ($this->lean) {
            return $summary;
        }

        return $summary + [
            // Whether this person's account has HR-officer (admin) access.
            'is_hr_officer' => (bool) $this->user?->hasRole('hr_officer'),
            'employment_type' => $this->employment_type,
            'work_location' => $this->work_location,
            'employee_role' => $this->employee_role,
            // The employment lifecycle, so the UI can tell an active employee from a former
            // one and offer the right action. `separation_date` is the last WORKING day (what payroll
            // prorates against); `deleted_at` is kept in sync with it by `EmployeeService::separate()`.
            'separation_type' => $this->separation_type,
            'separated_at' => $this->deleted_at?->format('Y-m-d'),
            // Set only by a rehire — the previous stint's start, so overwriting `hire_date` does not
            // erase when this person first joined.
            'previous_hire_date' => $this->previous_hire_date?->format('Y-m-d'),
            'base_salary' => $this->base_salary,
            // WHEN that exemption started — read-only, stamped when the flag is switched on.
            'management_since' => $this->management_since?->format('Y-m-d'),
            'phone' => $this->phone,
            'address' => $this->address,
            'address_house_no' => $this->address_house_no,
            'address_street' => $this->address_street,
            'address_barangay' => $this->address_barangay,
            'address_city' => $this->address_city,
            'address_province' => $this->address_province,
            'address_zip' => $this->address_zip,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'bank_name' => $this->bank_name,
            // MASKED. `••••4471` is enough to confirm the right account and useless to
            // anyone else. The full value is available only from the audited reveal endpoint, so a
            // list of 200 employees no longer ships 200 bank accounts to the browser.
            'bank_account' => Employee::maskSensitive($this->bank_account),
            'pay_frequency' => $this->pay_frequency ?? 'semi_monthly',
            // The DERIVED daily rate, and the configured regional floor when there is
            // one. Included because the daily rate is not something anyone can see from the monthly
            // figure they typed: it is salary ÷ the basis on the attendance policy. A warning, not
            // validation — the floor varies by region, industry and establishment size, and a block
            // would be wrong often enough to get routed around.
            'minimum_wage_exempt' => (bool) $this->minimum_wage_exempt,
            'minimum_wage_exempt_reason' => $this->minimum_wage_exempt_reason,
            'minimum_wage_check' => $this->when(
                $this->withPayrollChecks,
                fn () => app(\App\Modules\Hr\Services\PayrollService::class)
                    ->minimumWageCheckFor($this->resource),
            ),
            'currency' => $this->currency ?? 'PHP',
            'bank_account_type' => $this->bank_account_type,
            'bank_account_name' => Employee::maskSensitive($this->bank_account_name),
            // Membership numbers for the payslip header. There are no per-employee contribution
            // AMOUNTS any more — every employee is charged the company rates for their bracket.
            'tin' => Employee::maskSensitive($this->tin),
            'sss_number' => Employee::maskSensitive($this->sss_number),
            'philhealth_number' => Employee::maskSensitive($this->philhealth_number),
            'pagibig_number' => Employee::maskSensitive($this->pagibig_number),
            // Expanded personal record.
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name_extension' => $this->name_extension,
            'gender' => $this->gender,
            'civil_status' => $this->civil_status,
            'nationality' => $this->nationality,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'place_of_birth' => $this->place_of_birth,
            'current_address' => $this->current_address,
            'current_address_house_no' => $this->current_address_house_no,
            'current_address_street' => $this->current_address_street,
            'current_address_barangay' => $this->current_address_barangay,
            'current_address_city' => $this->current_address_city,
            'current_address_province' => $this->current_address_province,
            'current_address_zip' => $this->current_address_zip,
            'mothers_maiden_name' => $this->mothers_maiden_name,
            'fathers_name' => $this->fathers_name,
            // One entry per named sibling; the count may exceed the names listed.
            'siblings' => array_values(array_map(fn ($sibling) => [
                'name' => $sibling['name'] ?? '',
                'gender' => $sibling['gender'] ?? null,
            ], $this->siblings ?? [])),
            'number_of_siblings' => $this->number_of_siblings,
            'high_school' => $this->high_school,
            'college_course' => $this->college_course,
            'university' => $this->university,
            'height' => $this->height,
            'weight' => $this->weight,
            'health_condition' => $this->health_condition,
            // Employment history timeline (only loaded on the single-employee view).
            'employment_history' => $this->whenLoaded('employmentHistories', fn () => $this->employmentHistories->map(fn ($h) => [
                'id' => $h->id,
                'title' => $h->title,
                'start_date' => $h->start_date?->format('Y-m-d'),
                'end_date' => $h->end_date?->format('Y-m-d'),
                'status' => $h->status,
                'reason' => $h->reason,
            ]), []),
            'manager' => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->user?->name,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
