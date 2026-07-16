<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * Demo benefit plans for Company → Benefits + the employee "My Benefits" page.
 * Two HR-managed groups: monthly Allowances (a peso amount added to payroll) and
 * Company benefits (HMO, retirement, …). Government-mandated contributions live in
 * hr_contribution_settings and are system-managed — not seeded here.
 */
class BenefitProgramSeeder extends Seeder
{
    public function run(): void
    {
        // kind = allowance → the "Allowances" section (peso / month).
        $allowances = [
            ['name' => 'Meal Allowance', 'amount' => 2000, 'color' => '#3b82f6'],
            ['name' => 'Transportation Allowance', 'amount' => 1500, 'color' => '#ec4899'],
            ['name' => 'Gas Allowance', 'amount' => 1500, 'color' => '#d946ef'],
            ['name' => 'Mobile Load Allowance', 'amount' => 500, 'color' => '#22c55e'],
        ];

        // kind = company → the "Company benefits" section.
        $company = [
            [
                'name' => 'MediCare Plus HMO',
                'category' => 'hmo',
                'status' => 'Coming soon',
                'color' => '#ef4444',
                'description' => 'Group health coverage is planned for a future rollout. Set it up in advance and publish when ready.',
                'deduction_cadence' => 'per_cutoff',
                'tiers' => [
                    ['name' => 'Self', 'monthly_premium' => 1800, 'employee_share' => 240],
                    ['name' => 'Self +1', 'monthly_premium' => 3200, 'employee_share' => 480],
                    ['name' => 'Family', 'monthly_premium' => 5600, 'employee_share' => 840],
                ],
                'enroll' => false, // draft — nobody enrolled yet
            ],
            [
                'name' => 'Retirement Plus',
                'category' => 'retirement',
                'status' => 'Active',
                'color' => '#8b5cf6',
                'description' => 'Employer-matched retirement savings — 5% employee contribution, matched up to 6%.',
                'deduction_cadence' => null,
                'tiers' => [],
                'enroll' => true,
            ],
        ];

        $employeeIds = Employee::pluck('id')->all();

        foreach ($allowances as $a) {
            $program = BenefitProgram::firstOrCreate(
                ['name' => $a['name']],
                [
                    'category' => 'allowance',
                    'kind' => 'allowance',
                    'amount' => $a['amount'],
                    'color' => $a['color'],
                    'status' => 'Active',
                    'details' => [],
                    'is_active' => true,
                ],
            );
            $program->employees()->syncWithoutDetaching($employeeIds);
        }

        foreach ($company as $c) {
            $program = BenefitProgram::firstOrCreate(
                ['name' => $c['name']],
                [
                    'category' => $c['category'],
                    'kind' => 'company',
                    'description' => $c['description'],
                    'color' => $c['color'],
                    'status' => $c['status'],
                    'deduction_cadence' => $c['deduction_cadence'] ?? null,
                    'tiers' => $c['tiers'] ?? [],
                    'details' => [],
                    'is_active' => true,
                ],
            );
            if ($c['enroll']) {
                $program->employees()->syncWithoutDetaching($employeeIds);
            }
        }
    }
}
