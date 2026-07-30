<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use Illuminate\Database\Seeder;

/**
 * Government-contribution defaults (editable in Company → Benefits). Values are the
 * simplified rates from the build plan §5.9; the SSS entry is a PLACEHOLDER until
 * Miss Chris provides the official bracket table (open item #3) — swap `config`
 * for bracket rows then, no migration needed.
 */
class ContributionSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'type' => 'sss',
                'config' => [
                    'rate' => 0.045,          // employee share
                    'employer_rate' => 0.095, // employer share (SSS ee 4.5% / er 9.5%)
                    'salary_cap' => 30000,
                    'note' => 'Placeholder — replace with the official SSS bracket table.',
                ],
            ],
            [
                'type' => 'philhealth',
                // 5% total premium split 50/50 → 2.5% each side.
                'config' => ['rate' => 0.025, 'employer_rate' => 0.025, 'salary_floor' => 10000, 'salary_cap' => 100000],
            ],
            [
                'type' => 'pagibig',
                // Employer matches the employee 2%, both capped at ₱200.
                'config' => ['rate' => 0.02, 'cap' => 200, 'employer_rate' => 0.02, 'employer_cap' => 200],
            ],
        ];

        foreach ($settings as $s) {
            ContributionSetting::firstOrCreate(
                ['type' => $s['type']],
                ['config' => $s['config'], 'is_active' => true],
            );

            // Slice B3a — payroll reads DATED VERSIONS, not this row's `config`. On a fresh
            // install the backfill migration runs before this seeder and finds nothing, so
            // without seeding an opening version every statutory deduction would be ₱0.
            $c = $s['config'];
            ContributionVersion::firstOrCreate(
                ['type' => $s['type'], 'effective_from' => '2000-01-01'],
                [
                    'basis' => 'percentage',
                    'employee_rate' => $c['rate'] ?? 0,
                    'employer_rate' => $c['employer_rate'] ?? 0,
                    'salary_floor' => $c['salary_floor'] ?? null,
                    'salary_cap' => $c['salary_cap'] ?? null,
                    'employee_amount_cap' => $c['cap'] ?? null,
                    'employer_amount_cap' => $c['employer_cap'] ?? null,
                    'note' => $c['note'] ?? 'Opening version — seeded defaults.',
                ],
            );
        }
    }
}
