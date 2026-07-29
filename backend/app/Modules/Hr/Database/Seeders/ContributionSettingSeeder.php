<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\ContributionBracket;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use Illuminate\Database\Seeder;

/**
 * Government contributions, as data (Slice B3a, completed in B8).
 *
 * TWO VERSIONS PER TYPE, and both matter:
 *
 *   2000-01-01 — the OPENING version. Deliberately the old simplified rates, so a payroll cycle
 *                that ran before the current schedule took effect still reprices at what it
 *                actually charged. Never "correct" these to today's figures.
 *   2025-01-01 — the CURRENT published schedule, in force now.
 *
 * WHY THE CURRENT ONE IS HERE AT ALL (B8). It used to be typed in by hand: the SSS version on the
 * live database was created by a script, `created_by` was null, and nothing in the code recreated
 * it — so every `migrate:fresh --seed` quietly reverted SSS to 4.5 / 9.5 per cent with a ₱30,000
 * ceiling, and correctness depended on somebody remembering to retype the schedule. That is not
 * something that can be handed over.
 *
 * The SSS bands below are the published schedule (Circular 2024-006, effective January 2025),
 * transcribed by machine from the agency's own spreadsheet via `ContributionScheduleImporter` —
 * not typed. `min_salary` is where each band starts and it runs to the next band's start;
 * `employer_add_on` is that band's EC contribution, which is employer-only and is why the top
 * employer figure is ₱3,530 rather than ₱3,500. Verified on import: 5 per cent of the MSC
 * reproduces the printed employee amount on all 61 bands, and 10 per cent plus EC reproduces the
 * employer one — so there is nothing to store beyond the MSC itself.
 *
 * Idempotent: `firstOrCreate` per version, and the bands are written only when the version is new.
 */
class ContributionSettingSeeder extends Seeder
{
    /**
     * The published SSS schedule: [salary from, MSC, employer EC].
     *
     * A salary is charged on its band's MSC, not on the salary itself: ₱20,100 is charged on
     * ₱20,000. The first band starts at 0 (printed as "BELOW 5,250") so every salary lands
     * somewhere; the last runs to infinity, which is what makes ₱35,000 the ceiling.
     */
    private const SSS_2025_BANDS = [
            [0, 5000, 10],
            [5250, 5500, 10],
            [5750, 6000, 10],
            [6250, 6500, 10],
            [6750, 7000, 10],
            [7250, 7500, 10],
            [7750, 8000, 10],
            [8250, 8500, 10],
            [8750, 9000, 10],
            [9250, 9500, 10],
            [9750, 10000, 10],
            [10250, 10500, 10],
            [10750, 11000, 10],
            [11250, 11500, 10],
            [11750, 12000, 10],
            [12250, 12500, 10],
            [12750, 13000, 10],
            [13250, 13500, 10],
            [13750, 14000, 10],
            [14250, 14500, 10],
            [14750, 15000, 30],
            [15250, 15500, 30],
            [15750, 16000, 30],
            [16250, 16500, 30],
            [16750, 17000, 30],
            [17250, 17500, 30],
            [17750, 18000, 30],
            [18250, 18500, 30],
            [18750, 19000, 30],
            [19250, 19500, 30],
            [19750, 20000, 30],
            [20250, 20500, 30],
            [20750, 21000, 30],
            [21250, 21500, 30],
            [21750, 22000, 30],
            [22250, 22500, 30],
            [22750, 23000, 30],
            [23250, 23500, 30],
            [23750, 24000, 30],
            [24250, 24500, 30],
            [24750, 25000, 30],
            [25250, 25500, 30],
            [25750, 26000, 30],
            [26250, 26500, 30],
            [26750, 27000, 30],
            [27250, 27500, 30],
            [27750, 28000, 30],
            [28250, 28500, 30],
            [28750, 29000, 30],
            [29250, 29500, 30],
            [29750, 30000, 30],
            [30250, 30500, 30],
            [30750, 31000, 30],
            [31250, 31500, 30],
            [31750, 32000, 30],
            [32250, 32500, 30],
            [32750, 33000, 30],
            [33250, 33500, 30],
            [33750, 34000, 30],
            [34250, 34500, 30],
            [34750, 35000, 30],
    ];

    public function run(): void
    {
        $this->opening();
        $this->current();
    }

    /**
     * The pre-2025 rates, dated far in the past. Payroll resolves the version in force at the
     * CYCLE END, so an old run still prices on these.
     */
    private function opening(): void
    {
        $settings = [
            [
                'type' => 'sss',
                'config' => [
                    'rate' => 0.045,          // employee share
                    'employer_rate' => 0.095, // employer share (the pre-2025 4.5 / 9.5 split)
                    'salary_cap' => 30000,
                    'note' => 'Opening version — the pre-2025 simplified rates.',
                ],
            ],
            [
                'type' => 'philhealth',
                // 5 per cent total premium split 50/50 to 2.5 per cent each side.
                'config' => ['rate' => 0.025, 'employer_rate' => 0.025, 'salary_floor' => 10000, 'salary_cap' => 100000],
            ],
            [
                'type' => 'pagibig',
                // Employer matches the employee, both capped at ₱200.
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

    /** The schedules in force today. */
    private function current(): void
    {
        // SSS — bracket-based. The rates apply to the band's MSC, and the EC rides on each band.
        $sss = ContributionVersion::firstOrCreate(
            ['type' => 'sss', 'effective_from' => '2025-01-01'],
            [
                'basis' => 'bracket',
                'employee_rate' => 0.05,
                'employer_rate' => 0.10,
                // The ceiling is the top band's MSC. Kept in step with the bands themselves.
                'salary_cap' => 35000,
                'note' => 'SSS Circular 2024-006 — schedule effective January 2025.',
            ],
        );

        // Only on first creation: re-running must not duplicate 61 rows, and must not silently
        // replace a table HR has since corrected through the import screen.
        if ($sss->brackets()->doesntExist()) {
            foreach (self::SSS_2025_BANDS as [$min, $msc, $ec]) {
                ContributionBracket::create([
                    'contribution_version_id' => $sss->id,
                    'min_salary' => $min,
                    'msc' => $msc,
                    'employer_add_on' => $ec,
                ]);
            }
        }

        // PhilHealth — the premium is a percentage of basic salary, split evenly, between a
        // ₱10,000 floor and a ₱100,000 ceiling.
        ContributionVersion::firstOrCreate(
            ['type' => 'philhealth', 'effective_from' => '2025-01-01'],
            [
                'basis' => 'percentage',
                'employee_rate' => 0.025,
                'employer_rate' => 0.025,
                'salary_floor' => 10000,
                'salary_cap' => 100000,
                'note' => 'PhilHealth premium, shared equally between employee and employer.',
            ],
        );

        // Pag-IBIG — each side pays on a fund salary capped at ₱10,000. Expressed as a SALARY cap
        // rather than an amount cap: mathematically identical at a flat rate, but it says what the
        // rule actually is, and it stays correct if the rate ever changes.
        ContributionVersion::firstOrCreate(
            ['type' => 'pagibig', 'effective_from' => '2025-01-01'],
            [
                'basis' => 'percentage',
                'employee_rate' => 0.02,
                'employer_rate' => 0.02,
                'salary_cap' => 10000,
                'note' => 'Pag-IBIG — fund salary capped at ₱10,000.',
            ],
        );
    }
}
