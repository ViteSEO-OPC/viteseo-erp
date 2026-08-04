<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeDocument;
use App\Modules\Hr\Models\EmploymentHistory;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo people. Uses EmployeeService so each gets a user (login), an employee
 * record, and current-year leave balances — the real onboarding path. The fixed
 * demo logins (hr@ / employee@, password "password") are created here now, so
 * they have full employee records (they used to be bare users in the roles seeder).
 */
class EmployeeDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(EmployeeService $service): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        // Idempotency: skip if already seeded (service->create would hit the
        // users.email unique index on a re-run without a fresh migrate).
        if (Employee::query()->exists()) {
            return;
        }

        $dept = fn (string $code) => Department::where('code', $code)->value('id');

        // Managers / heads first, so reports can reference them.
        $dana = $service->create([
            'name' => 'Dana Reyes', 'email' => 'hr@viteseo.ph',
            'department_id' => $dept('HR'), 'job_title' => 'HR Manager',
            'employment_status' => 'regular', 'hire_date' => '2021-02-15',
            'base_salary' => 95000, 'phone' => '+63 917 555 0101',
            'address' => 'Makati City, Metro Manila',
            'bank_name' => 'BPI', 'bank_account' => '••••4471',
        ]);
        $dana->user->assignRole('hr_officer'); // HR officer AND an employee.

        $marco = $service->create([
            'name' => 'Marco Cruz', 'email' => 'marco@viteseo.ph',
            'department_id' => $dept('ENG'), 'job_title' => 'Engineering Manager',
            'employment_status' => 'regular', 'hire_date' => '2020-06-01',
            'base_salary' => 110000,
        ]);

        $maya = $service->create([
            'name' => 'Maya Villanueva', 'email' => 'employee@viteseo.ph',
            'department_id' => $dept('PRD'), 'job_title' => 'Product Manager',
            'employment_status' => 'regular', 'hire_date' => '2022-03-10',
            'base_salary' => 85000, 'phone' => '+63 917 000 1111',
            'address' => 'Quezon City, Metro Manila',
            'emergency_contact_name' => 'Rosa Villanueva',
            'emergency_contact_phone' => '+63 917 222 3333',
            'bank_name' => 'BPI', 'bank_account' => '••••4471',
        ]);

        // Individual contributors (some reporting to the managers above).
        $reports = [
            ['name' => 'Leo Santos', 'email' => 'leo@viteseo.ph', 'dept' => 'ENG', 'title' => 'Software Engineer', 'status' => 'regular', 'hire' => '2022-08-01', 'salary' => 62000, 'manager' => $marco->id],
            ['name' => 'Grace Tan', 'email' => 'grace@viteseo.ph', 'dept' => 'ENG', 'title' => 'QA Engineer', 'status' => 'probationary', 'hire' => '2026-05-05', 'salary' => 48000, 'manager' => $marco->id],
            ['name' => 'Bea Ramos', 'email' => 'bea@viteseo.ph', 'dept' => 'PRD', 'title' => 'UX Designer', 'status' => 'regular', 'hire' => '2023-01-20', 'salary' => 58000, 'manager' => $maya->id],
            ['name' => 'Carlo Diaz', 'email' => 'carlo@viteseo.ph', 'dept' => 'SAL', 'title' => 'Sales Executive', 'status' => 'regular', 'hire' => '2021-11-11', 'salary' => 52000, 'manager' => null],
            ['name' => 'Nina Flores', 'email' => 'nina@viteseo.ph', 'dept' => 'FIN', 'title' => 'Accountant', 'status' => 'regular', 'hire' => '2022-09-15', 'salary' => 55000, 'manager' => null],
            ['name' => 'Paolo Mendoza', 'email' => 'paolo@viteseo.ph', 'dept' => 'HR', 'title' => 'Recruiter', 'status' => 'contract', 'hire' => '2024-02-01', 'salary' => 45000, 'manager' => $dana->id],
            ['name' => 'Ella Reyes', 'email' => 'ella@viteseo.ph', 'dept' => 'SAL', 'title' => 'Sales Associate', 'status' => 'part_time', 'hire' => '2025-07-01', 'salary' => 30000, 'manager' => null],
        ];

        foreach ($reports as $r) {
            $service->create([
                'name' => $r['name'], 'email' => $r['email'],
                'department_id' => $dept($r['dept']), 'manager_id' => $r['manager'],
                'job_title' => $r['title'], 'employment_status' => $r['status'],
                'hire_date' => $r['hire'], 'base_salary' => $r['salary'],
            ]);
        }

        // Demo flair: Grace is still onboarding; Maya had a raise (2-row history).
        Employee::whereHas('user', fn ($q) => $q->where('email', 'grace@viteseo.ph'))
            ->update(['onboarding_status' => 'pending']);

        $maya = Employee::whereHas('user', fn ($q) => $q->where('email', 'employee@viteseo.ph'))->first();
        if ($maya) {
            $raised = (float) $maya->base_salary + 8000;
            $maya->update(['base_salary' => $raised]);
            SalaryHistory::create([
                'employee_id' => $maya->id,
                'base_salary' => $raised,
                'effective_date' => now(config('app.timezone_local'))->toDateString(),
                'reason' => 'Annual increase',
            ]);
        }

        // Backfill the split-name fields + light demo personal/job data so the
        // expanded employee record shows content instead of dashes.
        $typeLabel = [
            'regular' => 'Full-time Regular', 'probationary' => 'Full-time Probationary',
            'contract' => 'Contract', 'part_time' => 'Part-time',
        ];
        foreach (Employee::with('user')->get() as $e) {
            $parts = preg_split('/\s+/', trim((string) $e->user->name));
            $e->update([
                'first_name' => $parts[0] ?? '',
                'last_name' => count($parts) > 1 ? end($parts) : '',
                'nationality' => 'Filipino',
                'civil_status' => 'Single',
                'employment_type' => $typeLabel[$e->employment_status] ?? 'Full-time Regular',
                'work_location' => 'Manila HQ · Onsite',
                'employee_role' => $e->job_title,
                // Compensation defaults. A couple of non-default pay frequencies so
                // the payroll pay-groups feature has more than one group to show.
                'pay_frequency' => match ($e->employment_status) {
                    'part_time' => 'weekly',
                    'contract' => 'monthly',
                    default => 'semi_monthly',
                },
                'currency' => 'PHP',
                'bank_account_type' => 'Savings', 'bank_account_name' => $e->user->name,
                // Slice B7 — the three statutory columns are deliberately NOT seeded. They are
                // per-employee OVERRIDES that switch off the government rates for that person,
                // and seeding them gave every demo employee the identical ₱1,350 / ₱450 / ₱200
                // regardless of a salary range from ₱30k to ₱160k — suppressing ₱15,375/month of
                // deductions and producing employee/employer pairs that exist on no SSS table.
                // A demo dataset must show the engine working, not a company-wide exception.
                //
                // Slice B9 — the three allowance columns are not seeded either. Payroll reads
                // allowances from Company → Benefits, so a figure here is a number on the profile
                // that no payslip agrees with.
            ]);
            // Note: EmployeeService::create already opened each person's employment
            // history with their starting role, so no entry is created here.
        }

        // A fully-fleshed record for the primary demo employee (matches the design).
        $maya?->update([
            'first_name' => 'Maya', 'middle_name' => 'Reyes', 'last_name' => 'Villanueva',
            'gender' => 'Female', 'civil_status' => 'Single', 'nationality' => 'Filipino',
            'date_of_birth' => '1991-03-14', 'place_of_birth' => 'Quezon City',
            'phone' => '+63 917 555 0142',
            'address' => '42 Jasmine St, Quezon City, Metro Manila',
            'current_address' => 'Quezon City, Metro Manila',
            'mothers_maiden_name' => 'Santos, Elena C.',
            'fathers_name' => 'Villanueva, Roberto M.',
            'siblings' => [
                ['name' => 'Villanueva, Carlos R.', 'gender' => 'Male'],
                ['name' => 'Villanueva, Andrea R.', 'gender' => 'Female'],
            ],
            'number_of_siblings' => 3,
            'high_school' => 'Manila Science HS - 2007, Manila',
            'college_course' => 'BS Information Technology',
            'university' => 'University of the Philippines - 2011, Diliman',
            'emergency_contact_name' => 'R. Villanueva',
            'emergency_contact_relationship' => 'Father',
            'emergency_contact_phone' => '+63 917 555 0199',
            'height' => '162 cm', 'weight' => '55 kg', 'health_condition' => 'None',
            // Job tab (matches the design).
            'job_title' => 'Sr. Product Manager',
            'employment_type' => 'Full-time Regular',
            'work_location' => 'Manila HQ · Hybrid',
            'employee_role' => 'Senior Product Manager',
            // Compensation tab (matches the design; base salary stored monthly).
            'bank_account_type' => 'Savings',
            'bank_account' => '1234-5678-9012-34',
            'bank_account_name' => 'Maya R. Villanueva',
            // No statutory overrides and no allowance columns here either — see the notes above.
            'base_salary' => 160000,
        ]);

        if ($maya) {
            // Two-step employment history (replaces the single auto entry).
            $maya->employmentHistories()->delete();
            EmploymentHistory::insert([
                ['employee_id' => $maya->id, 'title' => 'Senior Product Manager', 'start_date' => '2023-08-01', 'end_date' => null, 'status' => 'current', 'created_at' => now(), 'updated_at' => now()],
                ['employee_id' => $maya->id, 'title' => 'Product Manager', 'start_date' => '2021-08-01', 'end_date' => '2023-08-01', 'status' => 'promoted', 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Three-step compensation history (monthly base → 1.5M / 1.62M / 1.92M per yr).
            $maya->salaryHistories()->delete();
            SalaryHistory::insert([
                ['employee_id' => $maya->id, 'base_salary' => 160000, 'effective_date' => '2023-08-01', 'reason' => 'Promotion to Senior PM', 'change_type' => 'promotion', 'created_at' => now(), 'updated_at' => now()],
                ['employee_id' => $maya->id, 'base_salary' => 135000, 'effective_date' => '2023-01-01', 'reason' => 'Merit increase', 'change_type' => 'merit', 'created_at' => now(), 'updated_at' => now()],
                ['employee_id' => $maya->id, 'base_salary' => 125000, 'effective_date' => '2021-08-01', 'reason' => 'Initial salary', 'change_type' => 'hire', 'created_at' => now(), 'updated_at' => now()],
            ]);

            // A few downloadable demo documents (tiny placeholder PDFs on the public disk).
            $demoDocs = [
                ['Employment contract.pdf', 'Signed', '2021-08-02'],
                ['Promotion letter 2023.pdf', 'Signed', '2023-07-28'],
                ['PMP certification.pdf', 'Uploaded', '2024-01-11'],
                ['National ID.pdf', 'Uploaded', '2024-01-10'],
            ];
            foreach ($demoDocs as [$docName, $docLabel, $docDate]) {
                $content = "%PDF-1.4\n% Demo placeholder for {$docName}\n";
                $path = "hr/employee-documents/{$maya->id}/" . Str::random(24) . '.pdf';
                Storage::disk('local')->put($path, $content);
                $doc = EmployeeDocument::create([
                    'employee_id' => $maya->id, 'name' => $docName, 'file_path' => $path,
                    'size_bytes' => strlen($content), 'label' => $docLabel,
                ]);
                EmployeeDocument::where('id', $doc->id)->update(['created_at' => $docDate, 'updated_at' => $docDate]);
            }
        }
    }
}
