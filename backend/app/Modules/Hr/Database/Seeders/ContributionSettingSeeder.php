<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\ContributionSetting;
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
        }
    }
}
