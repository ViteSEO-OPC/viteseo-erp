<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Database\Seeder;

/**
 * Demo joiners + leavers spread across recent months so the Reports →
 * Headcount & Movement chart has real month-over-month data to plot. Each is a
 * real employee record (created via EmployeeService); "leavers" are soft-deleted
 * with a back-dated deleted_at, which the movement query reads as their exit.
 */
class HeadcountMovementDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(EmployeeService $service): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        // Idempotent — skip if these demo records already exist.
        if (Employee::withTrashed()->whereHas('user', fn ($q) => $q->where('email', 'rafael@viteseo.ph'))->exists()) {
            return;
        }

        $dept = fn (string $code) => Department::where('code', $code)->value('id');

        // [name, email, dept code, title, hire_date, base_salary, left_on|null]
        $people = [
            ['Rafael Aquino', 'rafael@viteseo.ph', 'ENG', 'Software Engineer', '2026-01-12', 48000, null],
            ['Isabel Cruz', 'isabel@viteseo.ph', 'SAL', 'Sales Associate', '2026-02-03', 32000, '2026-05-20'],
            ['Miguel Torres', 'miguel@viteseo.ph', 'PRD', 'Product Analyst', '2026-03-08', 52000, null],
            ['Sofia Reyes', 'sofia@viteseo.ph', 'FIN', 'Finance Analyst', '2026-03-22', 50000, '2026-06-10'],
            ['Diego Ramos', 'diego@viteseo.ph', 'ENG', 'QA Engineer', '2026-04-15', 46000, null],
            ['Camille Lim', 'camille@viteseo.ph', 'HR', 'HR Associate', '2026-06-02', 40000, null],
        ];

        foreach ($people as [$name, $email, $code, $title, $hire, $salary, $leftOn]) {
            $employee = $service->create([
                'name' => $name,
                'email' => $email,
                'department_id' => $dept($code),
                'job_title' => $title,
                'employment_status' => 'regular',
                'hire_date' => $hire,
                'base_salary' => $salary,
            ]);

            if ($leftOn) {
                $employee->delete(); // soft delete = left the company
                Employee::withTrashed()->whereKey($employee->id)->update(['deleted_at' => $leftOn . ' 09:00:00']);
            }
        }
    }
}
