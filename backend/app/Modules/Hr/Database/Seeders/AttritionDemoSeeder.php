<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Database\Seeder;

/**
 * Demo departures across the last four quarters so the Attrition & Tenure report's
 * "Departures per quarter" chart has real, typed data (voluntary / involuntary).
 * Each is a real employee soft-deleted with a back-dated exit; hire dates sit
 * before the Headcount report's 12-month window so they don't show as joiners.
 */
class AttritionDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(EmployeeService $service): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        // Tag the two existing movement leavers with a reason.
        Employee::withTrashed()->whereHas('user', fn ($q) => $q->where('email', 'isabel@viteseo.ph'))->update(['separation_type' => 'voluntary']);
        Employee::withTrashed()->whereHas('user', fn ($q) => $q->where('email', 'sofia@viteseo.ph'))->update(['separation_type' => 'involuntary']);

        if (Employee::withTrashed()->whereHas('user', fn ($q) => $q->where('email', 'nora.dep@viteseo.ph'))->exists()) {
            return;
        }

        $dept = fn (string $code) => Department::where('code', $code)->value('id');

        // [name, email, dept, title, hire, salary, left_on, separation_type]
        $rows = [
            ['Nora Aquino', 'nora.dep@viteseo.ph', 'SAL', 'Sales Associate', '2022-05-10', 35000, '2025-08-14', 'voluntary'],
            ['Victor Cruz', 'victor.dep@viteseo.ph', 'ENG', 'Software Engineer', '2021-09-01', 60000, '2025-11-10', 'voluntary'],
            ['Elena Santos', 'elena.dep@viteseo.ph', 'PRD', 'Product Analyst', '2023-02-15', 50000, '2025-11-20', 'voluntary'],
            ['Marco Lim', 'marcol.dep@viteseo.ph', 'FIN', 'Accountant', '2022-11-05', 52000, '2025-11-28', 'involuntary'],
            ['Rina Torres', 'rina.dep@viteseo.ph', 'HR', 'Recruiter', '2023-07-01', 44000, '2026-02-12', 'voluntary'],
        ];

        foreach ($rows as [$name, $email, $code, $title, $hire, $salary, $leftOn, $type]) {
            $employee = $service->create([
                'name' => $name,
                'email' => $email,
                'department_id' => $dept($code),
                'job_title' => $title,
                'employment_status' => 'regular',
                'hire_date' => $hire,
                'base_salary' => $salary,
            ]);
            $employee->delete();
            Employee::withTrashed()->whereKey($employee->id)->update([
                'deleted_at' => $leftOn . ' 09:00:00',
                'separation_type' => $type,
            ]);
        }
    }
}
