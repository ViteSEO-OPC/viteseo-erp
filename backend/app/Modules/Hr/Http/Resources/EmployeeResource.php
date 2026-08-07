<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
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
            'base_salary' => $this->base_salary,
            // Fixed-salary + attendance-exempt management flag.
            'is_management' => (bool) $this->is_management,
            'phone' => $this->phone,
            'address' => $this->address,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'bank_name' => $this->bank_name,
            // FIN-022 — MASKED. `••••4471` is enough to confirm the right account and useless to
            // anyone else. The full value is available only from the audited reveal endpoint, so a
            // list of 200 employees no longer ships 200 bank accounts to the browser.
            'bank_account' => Employee::maskSensitive($this->bank_account),
            'pay_frequency' => $this->pay_frequency ?? 'semi_monthly',
            // FIN-030 — the DERIVED daily rate against the configured regional floor. Included
            // because the daily rate is not something anyone can see from the monthly figure they
            // typed: it is salary ÷ the basis on the attendance policy. Null when no floor has been
            // configured, in which case there is nothing to warn about. A warning, not validation —
            // the floor varies by region, industry and establishment size, and a block would be
            // wrong often enough to get routed around.
            'minimum_wage_check' => app(\App\Modules\Hr\Services\PayrollService::class)
                ->minimumWageCheckFor($this->resource),
            'currency' => $this->currency ?? 'PHP',
            'bank_account_type' => $this->bank_account_type,
            'bank_account_name' => Employee::maskSensitive($this->bank_account_name),
            'sss_contribution' => $this->sss_contribution,
            'pagibig_contribution' => $this->pagibig_contribution,
            'philhealth_contribution' => $this->philhealth_contribution,
            // Membership numbers (payslip header) — distinct from the *_contribution overrides.
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
