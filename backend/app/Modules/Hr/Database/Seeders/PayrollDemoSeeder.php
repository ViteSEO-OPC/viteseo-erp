<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\PayScheduleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Prior completed payroll runs so employees have payslip history. Computed with
 * reimbursements=0 so it doesn't consume the live approved-claims demo (the
 * current-cycle "Run payroll" button picks those up).
 */
class PayrollDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(PayrollService $service, PayScheduleService $schedule): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        if (PayrollRun::query()->exists()) {
            return;
        }

        $runBy = User::where('email', 'hr@viteseo.ph')->value('id');
        // Historical runs are all semi-monthly (the prior default), timing split.
        $frequency = 'semi_monthly';
        $periodsPerYear = PayScheduleService::periodsPerYear($frequency);
        $cycles = [
            ['2026-05-16', '2026-05-31'],
            ['2026-06-01', '2026-06-15'],
            ['2026-06-16', '2026-06-30'],
        ];

        foreach ($cycles as [$start, $end]) {
            $workingDays = $this->weekdaysBetween($start, $end);
            [$index, $countInMonth] = $schedule->monthPosition($frequency, null, $start, $end);
            $dist = [
                'index' => $index,
                'count' => $countInMonth,
                'deduction_timing' => 'every',
                'tax_timing' => 'every',
            ];

            $run = PayrollRun::create([
                'cycle_start' => $start,
                'cycle_end' => $end,
                'frequency' => $frequency,
                'status' => 'completed',
                'run_at' => $end . ' 14:00:00',
                'run_by' => $runBy,
            ]);

            $tg = $td = $tn = 0;
            $count = 0;

            foreach (Employee::all() as $employee) {
                // Historical runs: no attendance context, so no absences deducted.
                $slip = $service->payslipFor($employee, 0, 0, $periodsPerYear, $workingDays, $dist);
                Payslip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    ...$slip,
                    'status' => 'disbursed',
                ]);
                $tg += $slip['gross'];
                $td += $slip['total_deductions'];
                $tn += $slip['net_pay'];
                $count++;
            }

            $run->update([
                'total_gross' => round($tg, 2),
                'total_deductions' => round($td, 2),
                'total_net' => round($tn, 2),
                'employee_count' => $count,
            ]);
        }
    }

    /**
     * A naive Mon–Fri count, for demo payslips only (FIN-046).
     *
     * WHY IT LIVES HERE NOW. This was `PayScheduleService::workingDays()` — a weekend-only counter
     * sitting on the payroll schedule service, directly beside the schedule-aware
     * `AttendanceService::scheduledWorkdaysFor()` that real payroll actually uses. It was never
     * dead code (this seeder was its only caller), which made it worse than dead: two methods that
     * answer "how many workdays" differently, one of them on the service a future author would
     * reach for first, and nothing at the call site to say which was correct.
     *
     * It stays naive on purpose. Demo payslips only need plausible figures, and pulling the real
     * resolver in here would make seeding depend on each demo employee having a work schedule.
     * The name says what it does, so nobody mistakes it for the pay-critical one.
     */
    private function weekdaysBetween(string $start, string $end): int
    {
        $count = 0;
        $day = Carbon::parse($start);
        $last = Carbon::parse($end);

        while ($day->lte($last)) {
            if (! $day->isWeekend()) {
                $count++;
            }
            $day->addDay();
        }

        return $count;
    }
}
