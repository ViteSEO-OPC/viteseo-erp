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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            // Browser-facing URL for the profile picture; null falls back to initials.
            'photo_url' => $this->photo_url,
            // Identity comes from the linked user (relations are eager-loaded).
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            // Whether this person's account has HR-officer (admin) access.
            'is_hr_officer' => (bool) $this->user?->hasRole('hr_officer'),
            'job_title' => $this->job_title,
            'employment_status' => $this->employment_status,
            'employment_type' => $this->employment_type,
            'work_location' => $this->work_location,
            'employee_role' => $this->employee_role,
            'onboarding_status' => $this->onboarding_status,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            // The employment lifecycle, so the UI can tell an active employee from a former
            // one and offer the right action. `separation_date` is the last WORKING day (what payroll
            // prorates against); `deleted_at` is kept in sync with it by `EmployeeService::separate()`.
            'separation_type' => $this->separation_type,
            'separation_date' => $this->separation_date?->format('Y-m-d'),
            'separated_at' => $this->deleted_at?->format('Y-m-d'),
            'is_separated' => $this->separation_date !== null || $this->deleted_at !== null,
            // Set only by a rehire — the previous stint's start, so overwriting `hire_date` does not
            // erase when this person first joined.
            'previous_hire_date' => $this->previous_hire_date?->format('Y-m-d'),
            'base_salary' => $this->base_salary,
            // Fixed-salary + attendance-exempt management flag.
            'is_management' => (bool) $this->is_management,
            'phone' => $this->phone,
            'address' => $this->address,
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
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null,
            'manager' => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->user?->name,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
