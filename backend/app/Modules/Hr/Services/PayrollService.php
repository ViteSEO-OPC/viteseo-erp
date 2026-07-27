<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll engine (v2). Pay is attendance-connected — absences are deducted at the
 * daily rate from actual hr_attendance records. Statutory deductions are driven by
 * the editable Company → Benefits config + each employee's enrolment checklist
 * (ContributionService), NOT hardcoded formulas. Values remain SIMPLIFIED (the SSS
 * config is a placeholder rate until the official bracket table is supplied).
 */
class PayrollService
{
    public function __construct(
        private readonly ContributionService $contributions,
        private readonly PayScheduleService $schedule,
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
        private readonly HolidayService $holidays,
        private readonly AttendancePolicyService $attendancePolicy,
    ) {}

    /**
     * Dry-run (step 6b): what a PROPOSED attendance policy would deduct if it had been
     * active last period — WITHOUT saving the policy or a run. Reuses the same
     * DeductionService as a real run (a second caller, not new math), pricing only the
     * NEW minute-based portion (late + undertime) so HR sees the impact of the switch
     * before flipping it.
     *
     * @param  array<string, mixed>  $proposed  policy fields to preview (merged over current)
     * @return array{period:array{start:string,end:string}, employees_affected:int, total_deduction:float, employees_considered:int}
     */
    public function previewDeductions(array $proposed): array
    {
        $setting = PayrollSetting::query()->first();
        if (! $setting) {
            return ['period' => ['start' => '', 'end' => ''], 'employees_affected' => 0, 'employees_considered' => 0, 'total_deduction' => 0.0];
        }

        // The most recent COMPLETED period for the company default frequency: the period
        // immediately before the one covering today.
        $tz = config('app.timezone_local');
        $current = $this->schedule->currentPeriodFor($setting->frequency, $setting->anchor_date, $setting->pay_delay_days ?? 0);
        $prevRef = Carbon::parse($current['start'], $tz)->subDay();
        $prev = $this->schedule->currentPeriodFor($setting->frequency, $setting->anchor_date, $setting->pay_delay_days ?? 0, $prevRef);
        [$start, $end] = [$prev['start'], $prev['end']];

        // A transient, UNSAVED policy carrying the proposed values over today's policy.
        $policy = $this->attendancePolicy->get()->replicate();
        $policy->fill($proposed);

        $total = 0.0;
        $affected = 0;
        $considered = 0;

        $employees = Employee::whereNull('separation_type')
            ->where('is_management', false)
            ->whereDate('hire_date', '<=', $end)
            ->get();

        foreach ($employees as $employee) {
            $considered++;
            $frequency = $employee->pay_frequency ?: $setting->frequency;
            $periodsPerYear = PayScheduleService::periodsPerYear($frequency);
            $workingDays = $this->attendance->scheduledWorkdaysFor($employee, $start, $end);

            $dailyRate = $this->previewDailyRate($employee, $policy, $periodsPerYear, $workingDays);
            $detail = $this->attendance->unpaidDaysDetailFor($employee, $start, $end, $policy);

            // Only the NEW minute-based cost (the switch being previewed); absence/leave
            // are unchanged, so they aren't part of "what will this switch cost".
            $deduction = round(($detail->lateDays + $detail->undertimeDays) * $dailyRate, 2);
            if ($deduction > 0) {
                $affected++;
                $total += $deduction;
            }
        }

        return [
            'period' => ['start' => $start, 'end' => $end],
            'employees_affected' => $affected,
            'employees_considered' => $considered,
            'total_deduction' => round($total, 2),
        ];
    }

    /** The daily rate under a (possibly proposed) policy — mirrors payslipFor's basis logic. */
    private function previewDailyRate(Employee $employee, AttendancePolicy $policy, int $periodsPerYear, int $workingDays): float
    {
        $monthly = (float) $employee->base_salary;

        if ($policy->daily_rate_basis === 'fixed_divisor') {
            $divisor = max(1, (int) $policy->annual_divisor);

            return round(($monthly * 12) / $divisor, 2);
        }

        $perPeriodBase = $periodsPerYear > 0 ? round(($monthly * 12) / $periodsPerYear, 2) : 0.0;

        return $workingDays > 0 ? round($perPeriodBase / $workingDays, 2) : 0.0;
    }

    /**
     * Full per-employee payslip for one pay period. Base pay is the per-period slice
     * of the annual salary (monthly base × 12 ÷ periods/year), prorated by unpaid days
     * against the employee's scheduled workdays in the period.
     *
     * @return array<string, float>
     */
    /**
     * @param  array{index:int, count:int, deduction_timing:string, tax_timing:string}  $dist
     */
    public function payslipFor(
        Employee $employee,
        float $reimb,
        float $unpaidDays,
        int $periodsPerYear,
        int $workingDaysInPeriod,
        array $dist,
        float $holidayPremium = 0.0,
        ?UnpaidDaysResult $unpaidDetail = null,
    ): array {
        $monthlyBase = (float) $employee->base_salary;
        // The pay for THIS period: annual salary spread across the year's periods.
        $perPeriodBase = $periodsPerYear > 0 ? round(($monthlyBase * 12) / $periodsPerYear, 2) : 0.0;
        $dailyRate = $workingDaysInPeriod > 0 ? round($perPeriodBase / $workingDaysInPeriod, 2) : 0.0;
        $basePay = max(0, round($perPeriodBase - $unpaidDays * $dailyRate, 2));

        // Allowances (food + transport + load) are monthly, non-taxable de-minimis pay.
        // Spread across the year's periods like base, but NOT absence-prorated (they're
        // fixed benefits, not attendance-earned).
        $monthlyAllowance = (float) $employee->food_allowance
            + (float) $employee->transportation_allowance
            + (float) $employee->load_allowance;
        $perPeriodAllowance = $periodsPerYear > 0 ? round(($monthlyAllowance * 12) / $periodsPerYear, 2) : 0.0;

        // Only the contributions this employee is enrolled in, at the configured rates.
        $contribConfig = $this->contributions->effectiveFor($employee);

        // Per-employee statutory OVERRIDES: an amount set on the profile wins over the
        // company-config computation (for contributions the employee is enrolled in).
        $overrides = [
            'sss' => $employee->sss_contribution !== null ? (float) $employee->sss_contribution : null,
            'philhealth' => $employee->philhealth_contribution !== null ? (float) $employee->philhealth_contribution : null,
            'pagibig' => $employee->pagibig_contribution !== null ? (float) $employee->pagibig_contribution : null,
        ];

        // Withholding tracks compensation ACTUALLY earned: the monthly-equivalent of the
        // (absence-prorated) base pay. Equals the full monthly salary when there are no
        // unpaid days, so a normal period is unchanged; it scales down only when pay is
        // reduced, so we don't over-withhold a low-earning period. Contributions stay on
        // the full monthly salary (PH bracket model), so `contribBase` is unchanged.
        $taxableMonthly = $periodsPerYear > 0 ? round($basePay * $periodsPerYear / 12, 2) : 0.0;

        $slip = $this->computePayslip($basePay, $reimb, $perPeriodAllowance, $monthlyBase, $taxableMonthly, $contribConfig, $overrides, $dist, $holidayPremium);

        // Step 4b — persist how base_salary was derived, so the payslip explains itself.
        // The minute-level portion is all-zero until a deduction mode is enabled.
        $slip['working_days'] = $workingDaysInPeriod;
        $slip['daily_rate'] = $dailyRate;
        $slip['unpaid_days'] = round($unpaidDays, 2);
        $slip['late_minutes'] = $unpaidDetail?->lateMinutes ?? 0;
        $slip['undertime_minutes'] = $unpaidDetail?->undertimeMinutes ?? 0;
        $slip['late_deduction'] = round(($unpaidDetail?->lateDays ?? 0.0) * $dailyRate, 2);
        $slip['undertime_deduction'] = round(($unpaidDetail?->undertimeDays ?? 0.0) * $dailyRate, 2);

        return $slip;
    }

    /** The deduction-relevant policy fields, captured onto a run for reproducibility (4b). */
    private function policySnapshot(): array
    {
        $p = $this->attendancePolicy->get();

        return $p->only([
            'late_deduction_mode', 'lates_per_deduction', 'late_penalty_enabled', 'late_minutes_from', 'late_tiers',
            'undertime_deduction_mode', 'missing_clockout_mode',
            'deduction_rounding', 'deduction_block_minutes', 'daily_deduction_cap',
            'daily_rate_basis', 'annual_divisor', 'hours_per_day_divisor',
        ]);
    }

    /** How much of a MONTHLY deduction lands on this period, per the timing setting. */
    private function timingFactor(string $timing, int $index, int $count): float
    {
        return match ($timing) {
            'first' => $index === 0 ? 1.0 : 0.0,           // whole amount on the 1st cutoff
            'last' => $index >= $count - 1 ? 1.0 : 0.0,     // whole amount on the last cutoff
            default => $count > 0 ? 1.0 / $count : 1.0,     // 'every' — split evenly
        };
    }

    /**
     * Compute a payslip breakdown. `basePay` is the attendance-adjusted pay and
     * `allowances` the non-taxable de-minimis pay that go into gross; `contribBase` is
     * the (unreduced) monthly salary statutory CONTRIBUTIONS are computed on (PH bracket
     * model); `taxableMonthly` is the monthly-equivalent compensation WITHHOLDING TAX is
     * computed on (scales with actual earnings); `contribConfig` is the enrolled
     * contributions; `overrides` are per-employee statutory amounts that win over config.
     *
     * @param  array<string, array<string, mixed>>  $contribConfig
     * @param  array{sss:?float, philhealth:?float, pagibig:?float}  $overrides
     * @param  array{index:int, count:int, deduction_timing:string, tax_timing:string}  $dist
     * @return array<string, float>
     */
    public function computePayslip(float $basePay, float $reimb, float $allowances, float $contribBase, float $taxableMonthly, array $contribConfig, array $overrides, array $dist, float $holidayPremium = 0.0): array
    {
        // SSS/PhilHealth/Pag-IBIG are MONTHLY amounts (on the monthly base); the
        // timing setting decides which cutoff(s) in the month they land on.
        $sssM = $this->monthlyContribution('sss', $contribConfig, $overrides, $contribBase);
        $phM = $this->monthlyContribution('philhealth', $contribConfig, $overrides, $contribBase);
        $pgM = $this->monthlyContribution('pagibig', $contribConfig, $overrides, $contribBase);

        $cf = $this->timingFactor($dist['deduction_timing'] ?? 'every', $dist['index'] ?? 0, $dist['count'] ?? 1);
        $sss = round($sssM * $cf, 2);
        $philhealth = round($phM * $cf, 2);
        $pagibig = round($pgM * $cf, 2);

        // Employer counterpart of each contribution (config `employer_rate`). Same
        // enrolment + timing as the employee side, but NOT deducted from pay — tracked
        // only for government remittance (see the Payroll report / run CSV).
        $erSss = round($this->monthlyEmployerContribution('sss', $contribConfig, $contribBase) * $cf, 2);
        $erPh = round($this->monthlyEmployerContribution('philhealth', $contribConfig, $contribBase) * $cf, 2);
        $erPg = round($this->monthlyEmployerContribution('pagibig', $contribConfig, $contribBase) * $cf, 2);

        // Withholding tax on the taxable monthly compensation actually earned (minus the
        // employee's mandatory contributions; reimbursements and de-minimis allowances are
        // non-taxable), distributed by the tax-timing setting.
        $annualTaxable = max(0, ($taxableMonthly - ($sssM + $phM + $pgM)) * 12);
        $monthlyTax = $this->annualWithholdingTax($annualTaxable) / 12;
        $tf = $this->timingFactor($dist['tax_timing'] ?? 'every', $dist['index'] ?? 0, $dist['count'] ?? 1);
        $wht = round($monthlyTax * $tf, 2);

        // Holiday premium is added to gross (taxable in reality, but — like the rest of
        // this simplified engine — it isn't fed back into the withholding base above).
        $gross = round($basePay + $allowances + $reimb + $holidayPremium, 2);
        $totalDeductions = round($sss + $philhealth + $pagibig + $wht, 2);

        return [
            'base_salary' => round($basePay, 2),
            'reimbursements' => round($reimb, 2),
            'allowances' => round($allowances, 2),
            'holiday_premium' => round($holidayPremium, 2),
            'gross' => $gross,
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'withholding_tax' => $wht,
            'employer_sss' => $erSss,
            'employer_philhealth' => $erPh,
            'employer_pagibig' => $erPg,
            'total_deductions' => $totalDeductions,
            // Never issue a negative payslip — floor at 0 (a shortfall from statutory
            // deductions exceeding a heavily-prorated pay is HR's to reconcile).
            'net_pay' => max(0, round($gross - $totalDeductions, 2)),
        ];
    }

    /**
     * The monthly amount for one statutory contribution: 0 if the employee isn't
     * enrolled, else the per-employee override if set, else the config computation.
     *
     * @param  array<string, array<string, mixed>>  $config
     * @param  array{sss:?float, philhealth:?float, pagibig:?float}  $overrides
     */
    private function monthlyContribution(string $type, array $config, array $overrides, float $base): float
    {
        if (! isset($config[$type])) {
            return 0.0; // not enrolled → no deduction, override irrelevant
        }

        $override = $overrides[$type] ?? null;
        if ($override !== null) {
            return round(max(0, $override), 2);
        }

        return $this->contribution($config[$type], $base);
    }

    /**
     * The monthly EMPLOYER contribution for one type: 0 if the employee isn't enrolled,
     * else the config computation using `employer_rate`/`employer_cap`. Per-employee
     * overrides are the employee's own amount and never apply to the employer share.
     *
     * @param  array<string, array<string, mixed>>  $config
     */
    private function monthlyEmployerContribution(string $type, array $config, float $base): float
    {
        if (! isset($config[$type])) {
            return 0.0; // not enrolled → no employer share either
        }

        return $this->contribution($config[$type], $base, 'employer_rate', 'employer_cap');
    }

    /**
     * One contribution from its config. Supports rate + salary floor/cap and an
     * amount cap (Pag-IBIG). The shape is editable in Company → Benefits, so swapping
     * in the official SSS bracket table later is a config change, not a code change.
     * `$rateKey`/`$capKey` pick the employee (`rate`/`cap`) or employer
     * (`employer_rate`/`employer_cap`) side — the salary floor/cap are shared.
     *
     * @param  array<string, mixed>  $config
     */
    private function contribution(array $config, float $base, string $rateKey = 'rate', string $capKey = 'cap'): float
    {
        $creditable = $base;
        if (isset($config['salary_floor'])) {
            $creditable = max((float) $config['salary_floor'], $creditable);
        }
        if (isset($config['salary_cap'])) {
            $creditable = min((float) $config['salary_cap'], $creditable);
        }

        $amount = $creditable * (float) ($config[$rateKey] ?? 0);
        if (isset($config[$capKey])) {
            $amount = min((float) $config[$capKey], $amount);
        }

        return round(max(0, $amount), 2);
    }

    /**
     * Premium pay for actually working on a (non-working) holiday in the cycle. The
     * employee's daily rate × the statutory premium on top of the normal day's pay:
     * regular holiday +100%, special non-working +30% (special_working = normal day,
     * no premium). Only days with a present/late attendance record earn it. Simplified:
     * flat daily-rate basis, no OT/night-diff stacking.
     *
     * @param  array<string, string>  $holidayMap  date 'Y-m-d' => type
     */
    private function holidayPremiumFor(Employee $employee, string $start, string $end, int $periodsPerYear, int $workingDays, array $holidayMap): float
    {
        // Management staff are on a FIXED salary — no holiday premium, regardless of caller.
        if ($employee->isManagement()) {
            return 0.0;
        }

        if ($workingDays <= 0 || $holidayMap === []) {
            return 0.0;
        }

        $perPeriodBase = $periodsPerYear > 0 ? round(((float) $employee->base_salary * 12) / $periodsPerYear, 2) : 0.0;
        $dailyRate = round($perPeriodBase / $workingDays, 2);

        $worked = array_flip($this->attendance->workedDatesBetween($employee, $start, $end));

        $premium = 0.0;
        foreach ($holidayMap as $date => $type) {
            if (! isset($worked[$date])) {
                continue;
            }
            $premium += $dailyRate * match ($type) {
                'regular' => 1.0,
                'special_non_working' => 0.3,
                default => 0.0, // special_working — a normal working day
            };
        }

        return round($premium, 2);
    }

    /**
     * TRAIN annual graduated income tax (2023 onwards). We tax the annualised
     * period pay and divide back down, so withholding is correct for ANY pay
     * frequency (weekly, semi-monthly, monthly…) from one table.
     */
    private function annualWithholdingTax(float $t): float
    {
        $tax = match (true) {
            $t <= 250000 => 0,
            $t <= 400000 => 0.15 * ($t - 250000),
            $t <= 800000 => 22500 + 0.20 * ($t - 400000),
            $t <= 2000000 => 102500 + 0.25 * ($t - 800000),
            $t <= 8000000 => 402500 + 0.30 * ($t - 2000000),
            default => 2202500 + 0.35 * ($t - 8000000),
        };

        return round(max(0, $tax), 2);
    }

    public function listRuns(): Collection
    {
        return PayrollRun::withCount('payslips')->latest('cycle_start')->get();
    }

    public function runWithBreakdown(PayrollRun $run): PayrollRun
    {
        return $run->load(['payslips.employee.user', 'payslips.employee.department', 'runner']);
    }

    /** HR marks a payslip as disbursed (paid out manually) — the terminal state. */
    public function markDone(Payslip $payslip): Payslip
    {
        $payslip->update(['status' => 'disbursed']);

        return $payslip->load(['employee.user', 'employee.department']);
    }

    /**
     * Execute payroll for a cycle: one payslip per active employee, unpaid days from
     * hr_attendance/leave deducted, approved reimbursements folded into gross. Payslips
     * start "computed" and become "disbursed" when HR marks them paid out. Atomic.
     */
    /**
     * Compute payroll for a pay group's period. This ONLY computes (no disbursement),
     * so it's safely repeatable — re-running refreshes the period's payslips with the
     * latest salaries, attendance, reimbursements and timing settings.
     */
    public function run(string $cycleStart, string $cycleEnd, string $frequency, User $runner): PayrollRun
    {
        return DB::transaction(function () use ($cycleStart, $cycleEnd, $frequency, $runner) {
            $setting = PayrollSetting::current();
            // Frequency + period length drive per-period pay; month position + timing
            // settings decide which cutoff statutory deductions and tax land on.
            $periodsPerYear = PayScheduleService::periodsPerYear($frequency);
            [$monthIndex, $monthCount] = $this->schedule->monthPosition($frequency, $setting->anchor_date, $cycleStart, $cycleEnd);
            $dist = [
                'index' => $monthIndex,
                'count' => $monthCount,
                'deduction_timing' => $setting->deduction_timing,
                'tax_timing' => $setting->tax_timing,
            ];

            // Refresh semantics: reuse this group+period's run if one exists, otherwise
            // create it — so a re-run recomputes IN PLACE. The unique period index plus
            // this row lock stop a concurrent double-submit from creating two runs.
            $run = PayrollRun::where('cycle_start', $cycleStart)
                ->where('cycle_end', $cycleEnd)
                ->where('frequency', $frequency)
                ->lockForUpdate()
                ->first();
            // A pre-existing completed run means this is a re-run (recompute), not a
            // first-time run — the audit line distinguishes the two.
            $isRerun = $run && $run->run_at !== null;

            if ($run) {
                $run->update(['status' => 'processing']);
            } else {
                try {
                    $run = PayrollRun::create([
                        'cycle_start' => $cycleStart,
                        'cycle_end' => $cycleEnd,
                        'frequency' => $frequency,
                        'status' => 'processing',
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Lost the race: another request just created this period's run — reuse it.
                    $run = PayrollRun::where('cycle_start', $cycleStart)
                        ->where('cycle_end', $cycleEnd)
                        ->where('frequency', $frequency)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            // Preserve each employee's payslip status across a re-run: an already
            // "disbursed" payslip stays disbursed, recompute only refreshes its amounts.
            $priorStatus = $run->payslips()->pluck('status', 'employee_id');
            $paidElsewhere = [];

            $totalGross = $totalDed = $totalNet = 0;
            $count = 0;
            // Reimbursements auto-paid by this run (P2-8) — tallied for one audit line.
            $reimbPaidCount = 0;
            $reimbPaidTotal = 0.0;

            // Only the employees in this pay group (their pay_frequency, or the default).
            // Active only: skip anyone separated (voluntary/involuntary) and anyone not yet
            // hired as of this cycle. (Soft-deleted employees are already excluded.)
            $employees = Employee::with('user')
                ->whereRaw('COALESCE(NULLIF(pay_frequency, ""), ?) = ?', [$setting->frequency, $frequency])
                ->whereNull('separation_type')
                ->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $cycleEnd))
                ->get();

            // Holidays in this cycle (date => type), fetched once for the whole run.
            $holidayMap = $this->holidays->typedHolidaysBetween(Carbon::parse($cycleStart), Carbon::parse($cycleEnd));

            foreach ($employees as $employee) {
                // Double-pay guard: if this employee already has a payslip in ANOTHER run
                // whose period overlaps this cycle (e.g. their pay_frequency changed after
                // being run in another group), skip them — they're already covered.
                $overlaps = Payslip::where('employee_id', $employee->id)
                    ->where('payroll_run_id', '!=', $run->id)
                    ->whereHas('payrollRun', fn ($q) => $q
                        ->where('cycle_start', '<=', $cycleEnd)
                        ->where('cycle_end', '>=', $cycleStart))
                    ->exists();
                if ($overlaps) {
                    $paidElsewhere[] = $employee->id;

                    continue;
                }

                // Reimbursements folded into this period's pay: still-approved claims
                // dated in the cycle, PLUS any already paid by THIS run (so a re-run keeps
                // them folded). They are auto-flipped to `paid` + linked to the run below.
                $reimbursements = Reimbursement::where('employee_id', $employee->id)
                    ->whereBetween('expense_date', [$cycleStart, $cycleEnd])
                    ->where(fn ($q) => $q->where('status', 'approved')->orWhere('payroll_run_id', $run->id))
                    ->get();
                $reimbTotal = (float) $reimbursements->sum('amount');

                // Attendance is the source of truth, per the employee's OWN schedule:
                // scheduled workdays drive the daily rate; unpaid days (absences +
                // missing records on past workdays, minus approved leave) prorate pay.
                $workingDays = $this->attendance->scheduledWorkdaysFor($employee, $cycleStart, $cycleEnd);
                // Management staff are on a FIXED salary — the unpaidDaysDetailFor()/holidayPremiumFor()
                // helpers themselves return 0 for management, so pay is never prorated by attendance.
                $unpaidDetail = $this->attendance->unpaidDaysDetailFor($employee, $cycleStart, $cycleEnd);

                // Premium for working on a (non-working) holiday in this cycle.
                $holidayPremium = $this->holidayPremiumFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap);

                $slip = $this->payslipFor($employee, $reimbTotal, $unpaidDetail->unpaidDays, $periodsPerYear, $workingDays, $dist, $holidayPremium, $unpaidDetail);

                Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                    [...$slip, 'status' => $priorStatus[$employee->id] ?? 'computed'],
                );

                // P2-8: auto-flip the folded claims to paid + link them to this run.
                // Idempotent — on a re-run, claims already paid by this run are untouched.
                foreach ($reimbursements as $claim) {
                    if ($claim->status !== 'paid' || (int) $claim->payroll_run_id !== $run->id) {
                        $claim->update([
                            'status' => 'paid',
                            'payroll_run_id' => $run->id,
                            'reviewed_by' => $runner->id,
                            'reviewed_at' => now(),
                        ]);
                        $reimbPaidCount++;
                        $reimbPaidTotal += (float) $claim->amount;
                    }
                }

                $totalGross += $slip['gross'];
                $totalDed += $slip['total_deductions'];
                $totalNet += $slip['net_pay'];
                $count++;
            }

            // Keep only payslips for employees actually paid in this run — drop those no
            // longer in the pay group AND those skipped as already-paid-elsewhere, so a
            // re-run leaves no orphans or double-pay.
            $keep = $employees->pluck('id')->diff($paidElsewhere);
            $run->payslips()
                ->whereNotIn('employee_id', $keep)
                ->forceDelete();

            $run->update([
                'status' => 'completed',
                'total_gross' => round($totalGross, 2),
                'total_deductions' => round($totalDed, 2),
                'total_net' => round($totalNet, 2),
                'employee_count' => $count,
                'run_at' => now(),
                'run_by' => $runner->id,
                // Step 4b — the attendance policy as it stood when this run executed, so a
                // later policy change never silently re-prices these payslips.
                'policy_snapshot' => $this->policySnapshot(),
            ]);

            // Audit trail: who ran (or re-ran) which pay cycle, for how many people.
            $freqLabel = ucwords(str_replace('_', ' ', $frequency));
            $this->audit->log(
                $isRerun ? 'updated' : 'created',
                'Payroll',
                ($isRerun ? 'Re-ran' : 'Ran') . " {$freqLabel} payroll for "
                    . "{$cycleStart} – {$cycleEnd} — {$count} employee(s), net ₱"
                    . number_format(round($totalNet, 2), 2),
                $run,
            );

            // P2-8: one summary line for the reimbursements this run auto-paid.
            if ($reimbPaidCount > 0) {
                $this->audit->log(
                    'updated',
                    'Reimbursement',
                    "Auto-paid {$reimbPaidCount} reimbursement(s) folded into {$freqLabel} payroll "
                        . "({$cycleStart} – {$cycleEnd}) — ₱" . number_format(round($reimbPaidTotal, 2), 2),
                    $run,
                );
            }

            return $run->load(['payslips.employee.user', 'payslips.employee.department', 'runner']);
        });
    }

    /**
     * The caller's payslip HISTORY. A payslip is viewable once its run is finalized
     * (`completed`) — no request needed. Each slip also carries `hardcopy_status`,
     * the state of any signed-hard-copy request the employee raised for that period.
     */
    public function myPayslips(Employee $employee): Collection
    {
        $slips = $employee->payslips()
            ->with('payrollRun')
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->orderByDesc('hr_payroll_runs.cycle_start')
            ->select('hr_payslips.*')
            ->get();

        $statuses = $this->requestStatusMap($employee);

        return $slips->each(function (Payslip $slip) use ($statuses) {
            // `unlocked` = viewable. Kept as the attribute name the UI already reads.
            $slip->setAttribute('unlocked', $this->isViewable($slip));
            $slip->setAttribute('hardcopy_status', $statuses[$slip->id] ?? 'none');
        });
    }

    /**
     * A payslip is viewable to its owner once its payroll run is finalized (cutoff done).
     * Runs are `processing` mid-recompute, then `completed` — only the latter is released.
     */
    public function isViewable(Payslip $payslip): bool
    {
        return $payslip->payrollRun?->status === 'completed';
    }

    /**
     * Best signed-hard-copy request status per payslip for this employee
     * (approved > pending > rejected), so the history list can label each period.
     *
     * @return array<int, string> payslip_id => status
     */
    private function requestStatusMap(Employee $employee): array
    {
        $rank = ['rejected' => 1, 'pending' => 2, 'approved' => 3];
        $map = [];

        PayslipRequest::where('employee_id', $employee->id)
            ->whereNotNull('payslip_id')
            ->get(['payslip_id', 'status'])
            ->each(function (PayslipRequest $r) use (&$map, $rank) {
                $current = $map[$r->payslip_id] ?? null;
                if ($current === null || ($rank[$r->status] ?? 0) > ($rank[$current] ?? 0)) {
                    $map[$r->payslip_id] = $r->status;
                }
            });

        return $map;
    }

    /** Year-to-date totals across the employee's payslips this year. */
    public function ytd(Employee $employee, int $year): array
    {
        $slips = $employee->payslips()
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->whereYear('hr_payroll_runs.cycle_start', $year)
            ->select('hr_payslips.*')
            ->get();

        return [
            'gross' => round((float) $slips->sum('gross'), 2),
            'deductions' => round((float) $slips->sum('total_deductions'), 2),
            'net' => round((float) $slips->sum('net_pay'), 2),
            // 13th-month accrual: PH law = total BASIC salary earned in the year ÷ 12.
            // `base_salary` is the attendance-adjusted basic pay (excludes allowances/
            // reimbursements), so this is the correct basis. Projected until year-end.
            'thirteenth_month' => round((float) $slips->sum('base_salary') / 12, 2),
        ];
    }

    /**
     * Render one payslip to a DomPDF document (itemized earnings/deductions/net + optional
     * YTD + signature). Money is shown as "PHP" — DomPDF's default font lacks the ₱ glyph.
     *
     * @param  array<string, float>|null  $ytd
     */
    public function payslipPdf(Payslip $payslip, ?array $ytd = null): \Barryvdh\DomPDF\PDF
    {
        $payslip->loadMissing(['payrollRun.runner', 'employee.user']);

        $peso = fn ($n) => 'PHP ' . number_format((float) $n, 2);
        $fmt = fn ($d) => $d ? Carbon::parse($d)->format('M j, Y') : '—';

        return Pdf::loadView('hr::payslip', [
            'p' => (new PayslipResource($payslip))->toArray(request()),
            'ytd' => $ytd,
            'peso' => $peso,
            'fmt' => $fmt,
            'generatedAt' => now(config('app.timezone_local'))->format('M j, Y g:i A'),
        ]);
    }

    /**
     * A payroll run as CSV — one row per employee (basic → net, with each statutory
     * deduction) plus a TOTAL row that doubles as the remittance summary. Audit-logged.
     */
    public function runCsv(PayrollRun $run): string
    {
        $run->loadMissing(['payslips.employee.user', 'payslips.employee.department']);

        $sum = ['base' => 0.0, 'allow' => 0.0, 'holiday' => 0.0, 'reimb' => 0.0, 'sss' => 0.0, 'ph' => 0.0, 'pg' => 0.0, 'wht' => 0.0, 'er_sss' => 0.0, 'er_ph' => 0.0, 'er_pg' => 0.0];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, [
            'Employee', 'Department', 'Base', 'Allowances', 'Holiday Premium', 'Reimbursements', 'Gross',
            'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Total Deductions', 'Net Pay',
            'Employer SSS', 'Employer PhilHealth', 'Employer Pag-IBIG', 'Status',
        ], ',', '"', '');

        foreach ($run->payslips as $p) {
            $sum['base'] += (float) $p->base_salary;
            $sum['allow'] += (float) $p->allowances;
            $sum['holiday'] += (float) $p->holiday_premium;
            $sum['reimb'] += (float) $p->reimbursements;
            $sum['sss'] += (float) $p->sss;
            $sum['ph'] += (float) $p->philhealth;
            $sum['pg'] += (float) $p->pagibig;
            $sum['wht'] += (float) $p->withholding_tax;
            $sum['er_sss'] += (float) $p->employer_sss;
            $sum['er_ph'] += (float) $p->employer_philhealth;
            $sum['er_pg'] += (float) $p->employer_pagibig;

            fputcsv($out, [
                $p->employee->user?->name ?? $p->employee->name,
                $p->employee->department?->name,
                $p->base_salary, $p->allowances, $p->holiday_premium, $p->reimbursements, $p->gross,
                $p->sss, $p->philhealth, $p->pagibig, $p->withholding_tax,
                $p->total_deductions, $p->net_pay,
                $p->employer_sss, $p->employer_philhealth, $p->employer_pagibig, $p->status,
            ], ',', '"', '');
        }

        fputcsv($out, [
            'TOTAL', '',
            round($sum['base'], 2), round($sum['allow'], 2), round($sum['holiday'], 2), round($sum['reimb'], 2), (float) $run->total_gross,
            round($sum['sss'], 2), round($sum['ph'], 2), round($sum['pg'], 2), round($sum['wht'], 2),
            (float) $run->total_deductions, (float) $run->total_net,
            round($sum['er_sss'], 2), round($sum['er_ph'], 2), round($sum['er_pg'], 2), '',
        ], ',', '"', '');

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $this->audit->log(
            'exported',
            'Payroll',
            'Exported payroll run CSV for ' . $run->cycle_start?->format('Y-m-d') . ' – ' . $run->cycle_end?->format('Y-m-d'),
            $run,
        );

        return $csv;
    }

    /**
     * 13th-month pay per employee for a year — Σ basic salary earned ÷ 12. Payslip-driven,
     * so it naturally includes anyone paid during the year (even if since separated). No
     * accrual table: it's a live read over the year's payslips.
     *
     * @return array<int, array<string, mixed>>
     */
    public function thirteenthMonth(int $year): array
    {
        $rows = Payslip::query()
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->whereYear('hr_payroll_runs.cycle_start', $year)
            ->groupBy('hr_payslips.employee_id')
            ->selectRaw('hr_payslips.employee_id, SUM(hr_payslips.base_salary) as ytd_basic, COUNT(*) as periods')
            ->get();

        $employees = Employee::with(['user', 'department'])
            ->whereIn('id', $rows->pluck('employee_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($r) use ($employees) {
            $emp = $employees->get($r->employee_id);
            $basic = (float) $r->ytd_basic;

            return [
                'employee_id' => (int) $r->employee_id,
                'employee' => $emp?->user?->name ?? $emp?->name,
                'department' => $emp?->department?->name,
                'periods' => (int) $r->periods,
                'ytd_basic' => round($basic, 2),
                'thirteenth_month' => round($basic / 12, 2),
            ];
        })
            ->sortBy('employee', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
