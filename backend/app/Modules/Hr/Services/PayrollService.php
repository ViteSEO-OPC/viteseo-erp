<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LoanPayment;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
        private readonly ScheduleService $workSchedule,
        private readonly BenefitDeductionService $benefitDeductions,
        private readonly LoanService $loans,
    ) {}

    /**
     * The canonical daily rate, honoring the attendance policy's daily-rate basis:
     *   - fixed_divisor   → base × 12 ÷ annual_divisor (constant all year, DOLE-style)
     *   - period_workdays → this period's pay ÷ its scheduled workdays (the default)
     * Single source of truth (Slice A, 2026-07-27): payslipFor() and holidayPremiumFor() both
     * price a day through here, so absence, late/undertime, and holiday premium can never
     * disagree. Default basis reproduces the pre-Slice-A number.
     */
    private function dailyRateFor(Employee $employee, AttendancePolicy $policy, int $periodsPerYear, int $workingDays): float
    {
        $monthly = (float) $employee->base_salary;

        if ($policy->daily_rate_basis === 'fixed_divisor') {
            $divisor = max(1, (int) $policy->annual_divisor);

            return round(($monthly * 12) / $divisor, 2);
        }

        $perPeriodBase = $periodsPerYear > 0 ? round(($monthly * 12) / $periodsPerYear, 2) : 0.0;

        // Item 11 — a 0 divisor means the employee has no scheduled workdays in this period.
        // Returning 0.0 makes every absence free, so the whole period is paid in full; that is
        // far worse than failing. A real schedule always has workdays, so this is a
        // misconfiguration (or an empty custom_workdays) and should be seen, not absorbed.
        if ($workingDays <= 0) {
            throw ValidationException::withMessages([
                'work_schedule' => [
                    ($employee->user?->name ?? $employee->name)
                        . ' has no scheduled workdays in this pay period, so a daily rate cannot be'
                        . ' computed. Check their work schedule before running payroll.',
                ],
            ]);
        }

        return round($perPeriodBase / $workingDays, 2);
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
        ?AttendancePolicy $policy = null,
        float $overtimePay = 0.0,
        int $overtimeMinutes = 0,
        float $nightDiffPay = 0.0,
        int $nightDiffMinutes = 0,
        float $restDayPremium = 0.0,
        int $restDayMinutes = 0,
    ): array {
        $policy ??= $this->attendancePolicy->get();
        $monthlyBase = (float) $employee->base_salary;
        // The pay for THIS period: annual salary spread across the year's periods.
        $perPeriodBase = $periodsPerYear > 0 ? round(($monthlyBase * 12) / $periodsPerYear, 2) : 0.0;
        // Daily rate via the policy basis (Slice A) — the one helper every pricing path uses.
        $dailyRate = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDaysInPeriod);
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
        //
        // Slice 10 — PREMIUM PAY IS TAXABLE. Holiday premium, overtime, the night differential
        // and rest-day premium are all taxable compensation in PH, and all four were excluded
        // from this base. That was harmless while every one of them was always ₱0; now that they
        // actually pay, leaving them out understates withholding. Reimbursements stay out (a
        // reimbursement is not income).
        $taxablePeriodPay = $basePay + $holidayPremium + $overtimePay + $nightDiffPay + $restDayPremium;
        $taxableMonthly = $periodsPerYear > 0 ? round($taxablePeriodPay * $periodsPerYear / 12, 2) : 0.0;

        // Allowances are non-taxable de-minimis pay only up to the company's annual ceiling;
        // anything above it is compensation. No ceiling set ⇒ nothing taxable, as before.
        $taxableMonthly += $this->taxableAllowanceMonthly(
            $perPeriodAllowance,
            $periodsPerYear,
            $dist['deminimis_annual_cap'] ?? null,
        );

        // Slice 11a — recurring benefit shares (HMO, union dues) from Company → Benefits.
        // `count` is how many periods this calendar month has, so a MONTHLY share is split
        // the same way statutory contributions are.
        $otherDeductions = $this->benefitDeductions->forPeriod($employee, max(1, (int) ($dist['count'] ?? 1)));

        $slip = $this->computePayslip($basePay, $reimb, $perPeriodAllowance, $monthlyBase, $taxableMonthly, $contribConfig, $overrides, $dist, $holidayPremium, $overtimePay, $nightDiffPay, $restDayPremium, $otherDeductions);

        // Step 4b — persist how base_salary was derived, so the payslip explains itself.
        // The minute-level portion is all-zero until a deduction mode is enabled.
        $slip['working_days'] = $workingDaysInPeriod;
        $slip['daily_rate'] = $dailyRate;
        $slip['unpaid_days'] = round($unpaidDays, 2);
        $slip['late_minutes'] = $unpaidDetail?->lateMinutes ?? 0;
        $slip['undertime_minutes'] = $unpaidDetail?->undertimeMinutes ?? 0;
        $slip['overtime_minutes'] = $overtimeMinutes;
        $slip['night_diff_minutes'] = $nightDiffMinutes;
        $slip['rest_day_minutes'] = $restDayMinutes;
        $slip['late_deduction'] = round(($unpaidDetail?->lateDays ?? 0.0) * $dailyRate, 2);
        $slip['undertime_deduction'] = round(($unpaidDetail?->undertimeDays ?? 0.0) * $dailyRate, 2);
        // Item 13b.13 — the per-N-lates penalty, itemised rather than hidden in unpaid_days.
        $slip['late_penalty_days'] = round($unpaidDetail?->latePenaltyDays ?? 0.0, 2);

        return $slip;
    }

    /**
     * The taxable slice of this period's allowances, as a MONTHLY figure to match the rest of
     * the withholding base (Slice 10).
     *
     * De-minimis benefits are tax-free only up to a ceiling; the excess is compensation. Each
     * benefit has its own BIR limit, which we deliberately do NOT hardcode — a table of per-item
     * limits we can't keep current would be worse than no table. HR sets one annual ceiling for
     * the total instead. A null ceiling means "all of it is tax-free", exactly as before.
     */
    private function taxableAllowanceMonthly(float $perPeriodAllowance, int $periodsPerYear, mixed $annualCap): float
    {
        if ($annualCap === null || $periodsPerYear <= 0) {
            return 0.0;
        }

        $annualAllowance = $perPeriodAllowance * $periodsPerYear;
        $excess = max(0.0, $annualAllowance - max(0.0, (float) $annualCap));

        return round($excess / 12, 2);
    }

    /**
     * The base salary in force at a date, per employee, from `hr_salary_histories` (Slice 13c).
     *
     * THE BUG THIS FIXES. Payroll read `$employee->base_salary` live, so the salary a period was
     * priced at was simply "whatever it is right now". Give someone a raise and re-run an old
     * cycle and it repriced at the NEW figure — silently rewriting what they were paid months
     * ago. Slice 5's lock protects `disbursed` payslips; this protects the `computed` ones and,
     * more importantly, makes an old period reproducible at all.
     *
     * AS OF THE CYCLE END, deliberately: a raise dated inside the period applies to the WHOLE
     * period. There is no intra-period split — that would need the daily rate to change mid-cycle,
     * which the per-period model can't express. A raise dated on the next period's first day
     * therefore doesn't leak backwards, which is the case that actually matters.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>|Collection<int, Employee>  $employees
     * @return array<int, float> employee_id => base salary
     */
    private function salariesAsOf($employees, string $asOf): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $out = [];
        SalaryHistory::whereIn('employee_id', $ids)
            ->whereDate('effective_date', '<=', $asOf)
            // Latest effective date wins; `id` breaks a same-day tie toward the later entry.
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get(['employee_id', 'base_salary'])
            ->each(function (SalaryHistory $h) use (&$out) {
                $out[(int) $h->employee_id] = (float) $h->base_salary;
            });

        return $out;
    }

    /** Snapshot format. v1 was a flat map of 13 attendance-policy fields; v2 is grouped. */
    private const SNAPSHOT_VERSION = 2;

    /**
     * Every setting that priced this run, captured onto it so a later config change can never
     * make an old payslip inexplicable (4b, completed in Slice 8).
     *
     * WHY IT GREW. v1 hand-listed 13 deduction fields and drifted: it captured none of the seven
     * premium/overtime rate columns, `overtime_mode`, or `night_diff_pct` — all of which move
     * money — so "a policy change never silently re-prices these payslips" was simply untrue for
     * premium pay. The attendance policy is now taken from `array_keys(DEFAULTS)` so a new column
     * is captured the day it is added and this can't drift again. Contribution config and the pay
     * schedule are captured for the same reason: both set prices.
     *
     * INFORMATIONAL, NOT REPLAY. A re-run always uses the CURRENT rules — this records what was
     * in force, it does not restore it. That is why Slice 5's lock exists: the lock is what stops
     * an old cycle being re-priced, not this.
     */
    private function policySnapshot(PayrollSetting $setting): array
    {
        return [
            'version' => self::SNAPSHOT_VERSION,
            'captured_at' => now()->toIso8601String(),
            'attendance_policy' => $this->attendancePolicy->get()
                ->only(array_keys(AttendancePolicyService::DEFAULTS)),
            // Statutory rates + who is enrolled drive every deduction line.
            'contributions' => $this->contributions->settings()
                ->map(fn ($s) => [
                    'type' => $s->type,
                    'is_active' => (bool) $s->is_active,
                    'config' => $s->config,
                ])
                ->values()
                ->all(),
            // Frequency sets per-period pay; the timings decide which cutoff deductions land on.
            'payroll_setting' => [
                'frequency' => $setting->frequency,
                'deduction_timing' => $setting->deduction_timing,
                'tax_timing' => $setting->tax_timing,
                'anchor_date' => $setting->anchor_date?->toDateString(),
                'pay_delay_days' => $setting->pay_delay_days,
            ],
        ];
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
    public function computePayslip(float $basePay, float $reimb, float $allowances, float $contribBase, float $taxableMonthly, array $contribConfig, array $overrides, array $dist, float $holidayPremium = 0.0, float $overtimePay = 0.0, float $nightDiffPay = 0.0, float $restDayPremium = 0.0, array $otherDeductions = ['total' => 0.0, 'lines' => []]): array
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
        // employee's mandatory contributions), distributed by the tax-timing setting. What the
        // caller put into `taxableMonthly` (Slice 10): base pay + all four premium lines, plus
        // any allowance above the de-minimis ceiling. Reimbursements are never in it.
        $annualTaxable = max(0, ($taxableMonthly - ($sssM + $phM + $pgM)) * 12);
        $monthlyTax = $this->annualWithholdingTax($annualTaxable) / 12;
        $tf = $this->timingFactor($dist['tax_timing'] ?? 'every', $dist['index'] ?? 0, $dist['count'] ?? 1);
        $wht = round($monthlyTax * $tf, 2);

        // All four premium lines are in gross AND in the withholding base (Slice 10) — the
        // caller folds them into `taxableMonthly` before calling.
        $gross = round($basePay + $allowances + $reimb + $holidayPremium + $overtimePay + $nightDiffPay + $restDayPremium, 2);

        // Slice 11a — the employee's share of the benefits they're enrolled in (HMO, union
        // dues…). Post-tax: these are not statutory contributions, so they never reduce the
        // withholding base above.
        $otherTotal = round((float) ($otherDeductions['total'] ?? 0), 2);
        $totalDeductions = round($sss + $philhealth + $pagibig + $wht + $otherTotal, 2);

        // Item 1 — a payslip must reconcile. Net was floored at 0 while total_deductions was
        // not, so gross ₱2,000 − deductions ₱3,000 could read as net ₱0 and the ₱1,000 that
        // couldn't be collected just vanished. Cap what is actually taken at gross and record
        // the remainder, so HR can see what still needs recovering.
        $deductionCarryover = round(max(0, $totalDeductions - $gross), 2);
        $totalDeductions = round(min($totalDeductions, $gross), 2);

        return [
            'base_salary' => round($basePay, 2),
            'reimbursements' => round($reimb, 2),
            'allowances' => round($allowances, 2),
            'holiday_premium' => round($holidayPremium, 2),
            'overtime_pay' => round($overtimePay, 2),
            'night_diff_pay' => round($nightDiffPay, 2),
            'rest_day_premium' => round($restDayPremium, 2),
            'gross' => $gross,
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'withholding_tax' => $wht,
            // Slice 11a — recurring benefit shares, with the breakdown so the payslip can
            // name them rather than show one opaque figure.
            'other_deductions' => $otherTotal,
            'other_deductions_breakdown' => $otherDeductions['lines'] ?? [],
            'employer_sss' => $erSss,
            'employer_philhealth' => $erPh,
            'employer_pagibig' => $erPg,
            'total_deductions' => $totalDeductions,
            // What couldn't be taken because gross wasn't enough to cover it (item 1).
            'deduction_carryover' => $deductionCarryover,
            // round() last so this is always a float — max(0, …) returns int 0 on an
            // exact zero, which made net_pay the only money field with a shifting type.
            'net_pay' => round(max(0, $gross - $totalDeductions), 2),
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
    private function holidayPremiumFor(Employee $employee, string $start, string $end, int $periodsPerYear, int $workingDays, array $holidayMap, ?AttendancePolicy $policy = null): float
    {
        // Management staff are on a FIXED salary — no holiday premium, regardless of caller.
        if ($employee->isManagement()) {
            return 0.0;
        }

        if ($workingDays <= 0 || $holidayMap === []) {
            return 0.0;
        }

        // Same daily-rate basis as absence/late (Slice A) — priced through the shared helper.
        $policy ??= $this->attendancePolicy->get();
        $dailyRate = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays);

        $worked = array_flip($this->attendance->workedDatesBetween($employee, $start, $end));

        // Item 4 — when the holiday falls on a REST day the salary never covered that day at
        // all (the workdays-only basis spreads pay over scheduled workdays), so the whole rate
        // is owed, not just the add-on. Mirrors $salaryCoversRestDays in restDayPremiumFor().
        $tz = config('app.timezone_local');
        $salaryCoversEveryDay = $policy->daily_rate_basis === 'fixed_divisor'
            && (int) $policy->annual_divisor >= 365;

        $premium = 0.0;
        foreach ($holidayMap as $date => $type) {
            if (! isset($worked[$date])) {
                continue;
            }

            $multiplier = self::holidayPremiumMultiplier($type, $policy);

            if (! $salaryCoversEveryDay) {
                $isWorkday = (bool) ($this->workSchedule->effectiveFor($employee, Carbon::parse($date, $tz))['is_workday'] ?? true);
                if (! $isWorkday && $multiplier > 0) {
                    // Add-on → full rate: +100% becomes 200%, +30% becomes 130%.
                    $multiplier += 1.0;
                }
            }

            $premium += $dailyRate * $multiplier;
        }

        return round($premium, 2);
    }

    /**
     * The add-on multiple of the daily rate for WORKING a holiday of this type (P0-2). Reads the
     * HR-editable rate columns instead of hardcoded literals: a "total %" column (e.g. 200) becomes
     * an add-on of (pct − 100) ÷ 100 on top of the day's normal pay. At the DOLE-minimum defaults
     * (regular 200 → +1.0, special 130 → +0.3) this reproduces the old hardcoded behaviour exactly.
     * Pure + static so it is unit-testable without a DB (mirrors ViolationService::ladderMatch).
     */
    public static function holidayPremiumMultiplier(?string $type, AttendancePolicy $policy): float
    {
        // NULL = HR hasn't classified this holiday yet. `hr_holidays.type` is nullable by
        // design, so this argument really can be null — before it was typed `string` and a
        // single unclassified holiday inside a cycle threw a TypeError that failed the WHOLE
        // payroll run. Unclassified earns no premium; RunPayrollRequest blocks the run first.
        return match ($type) {
            'regular' => max(0, (int) $policy->premium_regular_holiday_pct - 100) / 100,
            'special_non_working' => max(0, (int) $policy->premium_special_day_pct - 100) / 100,
            default => 0.0, // special_working — a normal working day, no premium
        };
    }

    /**
     * Overtime pay for the cycle (OT-3): each APPROVED overtime request's hours × the hourly rate ×
     * the OT rate for that day's type (from the editable rate table). Stacks with holiday premium
     * WITHOUT double-counting — the premium pays the regular-day hours worked on a holiday, OT pays
     * the *extra* approved hours. Management → 0. Paid only in `approved_only` mode (auto detection is
     * OT-5; `none` = off). No-op until an OT request is approved, so wiring it changes no pesos today.
     *
     * @param  array<string, string>  $holidayMap  date 'Y-m-d' => type
     * @return array{pay: float, minutes: int}
     */
    private function overtimePayFor(Employee $employee, string $start, string $end, int $periodsPerYear, int $workingDays, array $holidayMap, ?AttendancePolicy $policy = null, ?array $byDate = null): array
    {
        if ($employee->isManagement()) {
            return ['pay' => 0.0, 'minutes' => 0];
        }

        $policy ??= $this->attendancePolicy->get();

        // WHICH minutes count is the mode's job (OT-5); pricing them is shared below.
        //   approved_only        → the hours on each APPROVED request (the safe, authorised default)
        //   auto_after_threshold → clock minutes past the scheduled end (opt-in, warned in the UI)
        //   none                 → overtime is switched off entirely
        $byDate ??= $this->overtimeMinutesByDate($employee, $start, $end, $policy);
        if ($byDate === []) {
            return ['pay' => 0.0, 'minutes' => 0];
        }

        $tz = config('app.timezone_local');
        $hourly = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays)
            / max(1, (int) $policy->hours_per_day_divisor);

        $pay = 0.0;
        $minutes = 0;
        foreach ($byDate as $date => $entry) {
            $mins = (int) $entry['minutes'];
            $isWorkday = (bool) ($this->workSchedule->effectiveFor($employee, Carbon::parse($date, $tz))['is_workday'] ?? true);
            $ratePct = self::overtimeMultiplierPct($holidayMap[$date] ?? null, $isWorkday, $policy);
            $pay += ($mins / 60) * $hourly * ($ratePct / 100);
            $minutes += $mins;
        }

        return ['pay' => round($pay, 2), 'minutes' => $minutes];
    }

    /**
     * Night-shift differential (ND-1): +`night_diff_pct`% of the hourly rate for every minute worked
     * between 22:00 and 06:00 — for ALL night work, not just overtime, per PH practice. Management → 0.
     *
     * STACKING: this is only the PREMIUM portion (the +10%), so it adds on top of the overtime line for
     * hours that are both. For night minutes that are ALSO overtime the premium is compounded onto the
     * overtime rate — hourly × OT% × night% — matching the PH rule that night pay is a percentage of the
     * applicable (overtime) hourly rate, not of the base. Ordinary night hours get hourly × night%.
     *
     * Remaining nuance: night hours on a holiday/rest day are premiumed at the base rate here; the day's
     * own premium is paid separately by holidayPremiumFor(). Compounding those is a Phase-3 refinement.
     *
     * @param  array<string, array{minutes:int, night:int}>|null  $otByDate  shared with overtimePayFor
     * @return array{pay: float, minutes: int}
     */
    private function nightDiffPayFor(Employee $employee, string $start, string $end, int $periodsPerYear, int $workingDays, array $holidayMap, ?AttendancePolicy $policy = null, ?array $otByDate = null): array
    {
        if ($employee->isManagement()) {
            return ['pay' => 0.0, 'minutes' => 0];
        }

        $policy ??= $this->attendancePolicy->get();
        $nightByDate = $this->attendance->nightMinutesFor($employee, $start, $end);
        if ($nightByDate === []) {
            return ['pay' => 0.0, 'minutes' => 0];
        }

        $otByDate ??= $this->overtimeMinutesByDate($employee, $start, $end, $policy);
        $tz = config('app.timezone_local');
        $hourly = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays)
            / max(1, (int) $policy->hours_per_day_divisor);
        $nightRate = (int) $policy->night_diff_pct / 100;

        $pay = 0.0;
        $minutes = 0;
        foreach ($nightByDate as $date => $nightMins) {
            // Night minutes that are also overtime earn the premium ON the overtime rate.
            $otNight = min($nightMins, (int) ($otByDate[$date]['night'] ?? 0));
            $plainNight = $nightMins - $otNight;

            $pay += ($plainNight / 60) * $hourly * $nightRate;
            if ($otNight > 0) {
                $isWorkday = (bool) ($this->workSchedule->effectiveFor($employee, Carbon::parse($date, $tz))['is_workday'] ?? true);
                $otRate = self::overtimeMultiplierPct($holidayMap[$date] ?? null, $isWorkday, $policy) / 100;
                $pay += ($otNight / 60) * $hourly * $otRate * $nightRate;
            }
            $minutes += $nightMins;
        }

        return ['pay' => round($pay, 2), 'minutes' => $minutes];
    }

    /** A full 8-hour day in minutes — the line between rest-day premium and rest-day overtime. */
    private const REST_DAY_PREMIUM_CAP_MIN = 8 * 60;

    /**
     * Rest-day premium (RD-1): pay for WORKING a scheduled rest day — the first 8 hours at
     * `premium_rest_day_pct`. Hours past the 8th are rest-day OVERTIME instead (see the cap in
     * overtimeMinutesByDate), so one rest day is never paid through both lines.
     *
     * FULL RATE vs PREMIUM-ONLY: under the default `period_workdays` basis (and the 261/313 divisors)
     * the salary is spread over SCHEDULED WORKDAYS only — a rest day isn't in it at all, so working one
     * earns the whole 130%. Only when the divisor counts every day (365) is the day already inside the
     * salary, and then just the +30% portion is owed on top.
     *
     * @param  array<string, int>  $restByDate  date => minutes worked on a rest day
     * @return array{pay: float, minutes: int}
     */
    private function restDayPremiumFor(Employee $employee, int $periodsPerYear, int $workingDays, array $restByDate, ?AttendancePolicy $policy = null): array
    {
        if ($restByDate === []) {
            return ['pay' => 0.0, 'minutes' => 0];
        }

        $policy ??= $this->attendancePolicy->get();
        $hourly = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays)
            / max(1, (int) $policy->hours_per_day_divisor);

        $pct = (int) $policy->premium_rest_day_pct;
        $salaryCoversRestDays = $policy->daily_rate_basis === 'fixed_divisor'
            && (int) $policy->annual_divisor >= 365;
        $rate = ($salaryCoversRestDays ? max(0, $pct - 100) : $pct) / 100;

        $pay = 0.0;
        $minutes = 0;
        foreach ($restByDate as $worked) {
            $premiumMinutes = min(self::REST_DAY_PREMIUM_CAP_MIN, $worked);
            $pay += ($premiumMinutes / 60) * $hourly * $rate;
            $minutes += $premiumMinutes;
        }

        return ['pay' => round($pay, 2), 'minutes' => $minutes];
    }

    /**
     * Which minutes count as overtime, per date — the mode's source of truth, shared by the overtime
     * pay line and the night differential (which compounds onto the OT rate for night OT minutes).
     *
     * @param  array<string, int>  $restByDate  worked rest days, whose first 8h are paid as a premium
     * @return array<string, array{minutes:int, night:int}>
     */
    private function overtimeMinutesByDate(Employee $employee, string $start, string $end, AttendancePolicy $policy, array $restByDate = []): array
    {
        $map = match ($policy->overtime_mode) {
            'approved_only' => $this->approvedOvertimeMinutes($employee, $start, $end),
            'auto_after_threshold' => $this->attendance->autoOvertimeMinutesFor(
                $employee, $start, $end, (int) $policy->overtime_threshold_minutes,
            ),
            default => [],
        };

        // On a worked rest day the first 8 hours are the rest-day PREMIUM, so only the excess can be
        // overtime. (A rest day with no attendance row isn't in $restByDate — no premium is paid for
        // it, so an approved request there still pays in full.)
        foreach ($restByDate as $date => $worked) {
            if (! isset($map[$date])) {
                continue;
            }
            $cap = max(0, $worked - self::REST_DAY_PREMIUM_CAP_MIN);
            if ($map[$date]['minutes'] > $cap) {
                $map[$date]['minutes'] = $cap;
                $map[$date]['night'] = min($map[$date]['night'], $cap);
            }
            if ($map[$date]['minutes'] <= 0) {
                unset($map[$date]);
            }
        }

        return $map;
    }

    /**
     * Approved-request overtime as date => minutes (the `approved_only` source), with the portion
     * falling in the night window derived from the request's own time span.
     *
     * @return array<string, array{minutes:int, night:int}>
     */
    private function approvedOvertimeMinutes(Employee $employee, string $start, string $end): array
    {
        $out = [];
        $requests = OvertimeRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$start, $end])
            ->get(['work_date', 'hours', 'time_from', 'time_to']);

        if ($requests->isEmpty()) {
            return [];
        }

        // What the time clock actually supports per date (Slice 6). An approval is a
        // permission to work overtime, not proof that it happened: before this, an approved
        // request paid in full even on a day the employee never clocked in — while that same
        // day was ALSO docked as an absence.
        $clocked = $this->attendance->overtimeCapacityFor($employee, $start, $end);

        foreach ($requests as $req) {
            $date = $req->work_date->toDateString();

            // No attendance that day (or a missing punch) → nothing to pay.
            $capacity = $clocked[$date] ?? 0;
            if ($capacity <= 0) {
                continue;
            }

            $night = 0;
            // Requests filed before OT-4 carry hours only — no window, so no night portion.
            if ($req->time_from && $req->time_to) {
                $from = self::hmToMinutes((string) $req->time_from);
                $to = self::hmToMinutes((string) $req->time_to);
                if ($to <= $from) {
                    $to += 24 * 60; // crosses midnight
                }
                $night = AttendanceService::nightOverlapMinutes($from, $to);
            }

            $out[$date] = [
                'minutes' => ($out[$date]['minutes'] ?? 0) + (int) round((float) $req->hours * 60),
                'night' => ($out[$date]['night'] ?? 0) + $night,
            ];
        }

        // Clamp each date to what was actually clocked: asking for 4h and staying 2h past the
        // shift pays 2h. The night portion is clamped with it so it can never exceed the paid
        // minutes and inflate the differential.
        foreach ($out as $date => $entry) {
            $capacity = $clocked[$date] ?? 0;
            if ($entry['minutes'] <= $capacity) {
                continue;
            }
            $out[$date]['minutes'] = $capacity;
            $out[$date]['night'] = min($entry['night'], $capacity);
        }

        return $out;
    }

    /** 'HH:MM(:SS)' → minute-of-day. */
    private static function hmToMinutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

        return $h * 60 + $m;
    }

    /**
     * The OT rate (whole percent) for a day, from the editable rate table (P0-1): regular holiday and
     * special-day from the holiday map; a non-workday that isn't a holiday is a REST day; otherwise an
     * ordinary day. Pure + static so it is unit-testable without a DB.
     */
    public static function overtimeMultiplierPct(?string $holidayType, bool $isWorkday, AttendancePolicy $policy): int
    {
        return match ($holidayType) {
            'regular' => (int) $policy->overtime_regular_holiday_pct,
            'special_non_working' => (int) $policy->overtime_rest_special_pct,
            default => $isWorkday
                ? (int) $policy->overtime_ordinary_pct
                : (int) $policy->overtime_rest_special_pct, // rest day (non-workday, non-holiday)
        };
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
        return $run->load(['payslips.employee.user', 'payslips.employee.department', 'runner', 'locker']);
    }

    /**
     * Seal a finished cycle (Slice 5). Only when every payslip in it has actually been paid
     * out — locking a half-disbursed run would strand the rest, since a locked run can't be
     * recomputed. Reversible via unlock(), which is audited: the lock is a safety catch
     * against an accidental re-run, not a legal seal.
     */
    public function lock(PayrollRun $run, User $user): PayrollRun
    {
        if ($run->isLocked()) {
            throw ValidationException::withMessages([
                'locked_at' => ['This payroll run is already locked.'],
            ]);
        }

        $total = $run->payslips()->count();
        if ($total === 0) {
            throw ValidationException::withMessages([
                'locked_at' => ['There is nothing to lock — this run has no payslips.'],
            ]);
        }

        $pending = $run->payslips()->where('status', '!=', 'disbursed')->count();
        if ($pending > 0) {
            throw ValidationException::withMessages([
                'locked_at' => [
                    "{$pending} of {$total} payslip(s) are not disbursed yet. Mark them all as disbursed before locking the run.",
                ],
            ]);
        }

        $run->update(['locked_at' => now(), 'locked_by' => $user->id]);

        $this->audit->log(
            'updated',
            'Payroll',
            'Locked the ' . $run->cycle_start?->format('Y-m-d') . ' – ' . $run->cycle_end?->format('Y-m-d')
                . " payroll run ({$total} payslip(s)) — it can no longer be recomputed",
            $run,
        );

        return $run->load(['payslips.employee.user', 'payslips.employee.department', 'runner', 'locker']);
    }

    /** Re-open a locked cycle. Audited — this is the escape hatch, so it must be visible. */
    public function unlock(PayrollRun $run, User $user): PayrollRun
    {
        if (! $run->isLocked()) {
            throw ValidationException::withMessages([
                'locked_at' => ['This payroll run is not locked.'],
            ]);
        }

        $lockedAt = $run->locked_at->format('M j, Y');
        $run->update(['locked_at' => null, 'locked_by' => null]);

        $this->audit->log(
            'updated',
            'Payroll',
            'Unlocked the ' . $run->cycle_start?->format('Y-m-d') . ' – ' . $run->cycle_end?->format('Y-m-d')
                . " payroll run (locked {$lockedAt}) — it can be recomputed again",
            $run,
        );

        return $run->load(['payslips.employee.user', 'payslips.employee.department', 'runner', 'locker']);
    }

    /**
     * HR marks a payslip as disbursed (paid out manually) — the terminal state.
     *
     * Slice 12 — this is also where reimbursements actually become `paid`. Running payroll only
     * SCHEDULES them onto a payslip; the money leaves the company here, so this is the honest
     * moment to record it.
     */
    public function markDone(Payslip $payslip): Payslip
    {
        return DB::transaction(function () use ($payslip) {
            $payslip->update(['status' => 'disbursed']);

            $claims = Reimbursement::where('employee_id', $payslip->employee_id)
                ->where('payroll_run_id', $payslip->payroll_run_id)
                ->where('status', 'scheduled')
                ->get();

            foreach ($claims as $claim) {
                $claim->update(['status' => 'paid', 'reviewed_at' => now()]);
            }

            if ($claims->isNotEmpty()) {
                $this->audit->log(
                    'updated', 'Reimbursement',
                    'Paid ' . $claims->count() . ' reimbursement(s) with the disbursed payslip — ₱'
                        . number_format((float) $claims->sum('amount'), 2),
                    $payslip,
                );
            }

            return $payslip->load(['employee.user', 'employee.department']);
        });
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
        $startedAt = microtime(true);

        $run = DB::transaction(function () use ($cycleStart, $cycleEnd, $frequency, $runner) {
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
                // Slice 10 — the ceiling above which allowances become taxable (null = none).
                'deminimis_annual_cap' => $setting->deminimis_annual_cap,
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

            // A locked cycle is closed for good (Slice 5) — refuse before anything is touched.
            if ($run && $run->isLocked()) {
                throw ValidationException::withMessages([
                    'cycle_start' => [
                        'This payroll run is locked (' . $run->locked_at->format('M j, Y')
                            . ') and can no longer be recomputed. Unlock it first if you really need to change it.',
                    ],
                ]);
            }

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

            // Payslips from a previous run of this same period, keyed by employee.
            $priorSlips = $run->payslips()->get()->keyBy('employee_id');
            $priorStatus = $priorSlips->map(fn (Payslip $p) => $p->status);
            $paidElsewhere = [];
            // Employees whose pay was already disbursed and is therefore left untouched.
            $skippedDisbursed = [];

            $totalGross = $totalDed = $totalNet = 0;
            $count = 0;
            // Reimbursements auto-paid by this run (P2-8) — tallied for one audit line.
            $reimbPaidCount = 0;
            $reimbPaidTotal = 0.0;

            // Only the employees in this pay group (their pay_frequency, or the default), and
            // only those employed at some point during the cycle.
            //
            // FINAL PAY (Slice 4): a separation is recorded as `separation_type` PLUS a
            // soft-delete, so a leaver was doubly invisible here — the old `whereNull(
            // 'separation_type')` filter and Eloquent's own soft-delete scope. Anyone who left
            // mid-cycle therefore got no payslip at all for the days they had worked. We now
            // pull them back in with withTrashed() when their last day falls on/after the
            // cycle start; unpaidDaysDetailFor() prorates the days after they left.
            $employees = Employee::withTrashed()
                ->with('user')
                ->whereRaw('COALESCE(NULLIF(pay_frequency, ""), ?) = ?', [$setting->frequency, $frequency])
                ->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $cycleEnd))
                ->where(function ($q) use ($cycleStart) {
                    // Currently employed: never separated and not soft-deleted for any reason.
                    $q->where(fn ($active) => $active
                        ->whereNull('separation_type')
                        ->whereNull('separation_date')
                        ->whereNull('deleted_at'))
                        // …or this is their final cycle. A separation with no recorded date
                        // stays excluded — we won't guess someone's last working day.
                        ->orWhere('separation_date', '>=', $cycleStart);
                })
                ->get();

            // Holidays in this cycle (date => type), fetched once for the whole run.
            $holidayMap = $this->holidays->typedHolidaysBetween(Carbon::parse($cycleStart), Carbon::parse($cycleEnd));

            // The attendance policy drives the daily-rate basis (Slice A) — fetch once, reuse.
            $policy = $this->attendancePolicy->get();

            // Slice 13c — the salary each employee was actually on during this period.
            $salaryAsOf = $this->salariesAsOf($employees, $cycleEnd);

            // Slice 15 — who already has a payslip in an OVERLAPPING run, resolved in ONE query
            // instead of an exists() per employee. Same question, same answer, 1/N the round trips.
            $paidInAnotherRun = Payslip::whereIn('employee_id', $employees->pluck('id'))
                ->where('payroll_run_id', '!=', $run->id)
                ->whereHas('payrollRun', fn ($q) => $q
                    ->where('cycle_start', '<=', $cycleEnd)
                    ->where('cycle_end', '>=', $cycleStart))
                ->pluck('employee_id')
                ->unique()
                ->flip();

            foreach ($employees as $employee) {
                // Slice 13c — price this period at the salary that was in force THEN, not the
                // one on the record today. Set on the in-memory model (never saved) so every
                // pricing path — daily rate, holiday premium, the overtime hourly rate, the
                // rest-day premium — picks it up consistently, instead of threading the figure
                // through six signatures and risking one of them being missed.
                if (isset($salaryAsOf[$employee->id])) {
                    $employee->base_salary = $salaryAsOf[$employee->id];
                }

                // Already paid out (Slice 5): never recompute a disbursed payslip. Its status
                // used to be preserved while its AMOUNTS were silently rewritten — a salary
                // edit plus a re-run of an old cycle changed money that had already left the
                // company. Its existing totals still roll into this run's summary.
                $existing = $priorSlips->get($employee->id);
                if ($existing && $existing->status === 'disbursed') {
                    $skippedDisbursed[] = $employee->id;
                    $totalGross += (float) $existing->gross;
                    $totalDed += (float) $existing->total_deductions;
                    $totalNet += (float) $existing->net_pay;
                    $count++;

                    continue;
                }

                // Double-pay guard: if this employee already has a payslip in ANOTHER run
                // whose period overlaps this cycle (e.g. their pay_frequency changed after
                // being run in another group), skip them — they're already covered.
                if ($paidInAnotherRun->has($employee->id)) {
                    $paidElsewhere[] = $employee->id;

                    continue;
                }

                // Reimbursements folded into this period's pay: still-approved claims
                // dated in the cycle, PLUS any already paid by THIS run (so a re-run keeps
                // them folded). They are auto-flipped to `paid` + linked to the run below.
                $reimbursements = Reimbursement::where('employee_id', $employee->id)
                    ->whereBetween('expense_date', [$cycleStart, $cycleEnd])
                    ->where(fn ($q) => $q->whereIn('status', ['approved', 'scheduled'])
                        ->orWhere('payroll_run_id', $run->id))
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
                $holidayPremium = $this->holidayPremiumFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy);
                // Rest days worked feed BOTH the rest-day premium and the overtime cap below.
                $restByDate = $this->attendance->restDayWorkedMinutesFor($employee, $cycleStart, $cycleEnd, $holidayMap);
                $restDay = $this->restDayPremiumFor($employee, $periodsPerYear, $workingDays, $restByDate, $policy);
                // One overtime source, shared by the OT line and the night differential.
                $otByDate = $this->overtimeMinutesByDate($employee, $cycleStart, $cycleEnd, $policy, $restByDate);
                $overtime = $this->overtimePayFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy, $otByDate);
                $nightDiff = $this->nightDiffPayFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy, $otByDate);

                $slip = $this->payslipFor($employee, $reimbTotal, $unpaidDetail->unpaidDays, $periodsPerYear, $workingDays, $dist, $holidayPremium, $unpaidDetail, $policy, $overtime['pay'], $overtime['minutes'], $nightDiff['pay'], $nightDiff['minutes'], $restDay['pay'], $restDay['minutes']);

                $payslip = Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                    [...$slip, 'status' => $priorStatus[$employee->id] ?? 'computed'],
                );

                // Slice 11b — loans/cash advances come LAST, because what they can collect
                // depends on what the payslip can still afford. They're post-tax and don't
                // touch gross, so folding them in afterwards is exact rather than a re-compute.
                // Always called (even at zero) so a cancelled loan's stale ledger row is cleared.
                $loans = $this->loans->collectForPeriod(
                    $employee, $run, $cycleEnd, (float) $slip['net_pay'], $payslip,
                );

                if ($loans['total'] > 0) {
                    $slip['other_deductions'] = round((float) $slip['other_deductions'] + $loans['total'], 2);
                    $slip['other_deductions_breakdown'] = [...$slip['other_deductions_breakdown'], ...$loans['lines']];
                    $slip['total_deductions'] = round((float) $slip['total_deductions'] + $loans['total'], 2);
                    $slip['net_pay'] = max(0, round((float) $slip['net_pay'] - $loans['total'], 2));

                    $payslip->update([
                        'other_deductions' => $slip['other_deductions'],
                        'other_deductions_breakdown' => $slip['other_deductions_breakdown'],
                        'total_deductions' => $slip['total_deductions'],
                        'net_pay' => $slip['net_pay'],
                    ]);
                }

                // P2-8: auto-flip the folded claims to paid + link them to this run.
                // Idempotent — on a re-run, claims already paid by this run are untouched.
                // Slice 12 — computing is not paying. A claim folded into a payslip becomes
                // `scheduled` ("included in the Jul 16–31 payslip"), NOT `paid`; it only becomes
                // paid when HR marks that payslip disbursed (markDone). Before this, running
                // payroll flipped claims to `paid` before a peso had left the company, and a
                // later re-run that dropped the employee left them stranded as paid against a
                // run with no payslip.
                //
                // An already-`paid` claim is left alone: money out is history (Slice 5).
                foreach ($reimbursements as $claim) {
                    if ($claim->status === 'paid') {
                        continue;
                    }
                    if ($claim->status !== 'scheduled' || (int) $claim->payroll_run_id !== $run->id) {
                        $claim->update([
                            'status' => 'scheduled',
                            'payroll_run_id' => $run->id,
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
            //
            // NEVER a disbursed one (Slice 4): money already paid out is history. Before this,
            // separating an employee and re-running an old cycle force-deleted the payslip
            // they had actually been paid from.
            $keep = $employees->pluck('id')->diff($paidElsewhere);
            $run->payslips()
                ->whereNotIn('employee_id', $keep)
                ->where('status', 'computed')
                ->forceDelete();

            // Slice 11b — same cleanup for the loan ledger: an employee dropped from this run
            // must not keep a repayment against it, or their balance would stay wrongly reduced.
            LoanPayment::where('payroll_run_id', $run->id)
                ->whereHas('loan', fn ($q) => $q->whereNotIn('employee_id', $keep))
                ->delete();

            // Slice 12 — and release reimbursements scheduled onto this run for anyone no longer
            // in it, so they return to the approved pool instead of being stranded against a run
            // that no longer pays them. An already-`paid` claim is never touched.
            Reimbursement::where('payroll_run_id', $run->id)
                ->where('status', 'scheduled')
                ->whereNotIn('employee_id', $keep)
                ->update(['status' => 'approved', 'payroll_run_id' => null]);

            $run->update([
                'status' => 'completed',
                'total_gross' => round($totalGross, 2),
                'total_deductions' => round($totalDed, 2),
                'total_net' => round($totalNet, 2),
                'employee_count' => $count,
                'run_at' => now(),
                'run_by' => $runner->id,
                // Step 4b / Slice 8 — every rule that priced this run, so an old payslip can
                // always be explained. A record, not a replay: see policySnapshot().
                'policy_snapshot' => $this->policySnapshot($setting),
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

            // Slice 5: say plainly whose pay this re-run left alone.
            if ($skippedDisbursed !== []) {
                $this->audit->log(
                    'updated',
                    'Payroll',
                    count($skippedDisbursed) . ' already-disbursed payslip(s) were left unchanged by this recompute',
                    $run,
                );
            }

            // P2-8: one summary line for the reimbursements this run auto-paid.
            if ($reimbPaidCount > 0) {
                $this->audit->log(
                    'updated',
                    'Reimbursement',
                    "Scheduled {$reimbPaidCount} reimbursement(s) into {$freqLabel} payroll "
                        . "({$cycleStart} – {$cycleEnd}) — ₱" . number_format(round($reimbPaidTotal, 2), 2),
                    $run,
                );
            }

            return $run->load(['payslips.employee.user', 'payslips.employee.department', 'runner', 'locker']);
        });

        // Slice 15 — one line so a slow run is visible without guesswork. The whole run is a
        // single transaction, so its duration is also how long the payroll tables stay locked.
        Log::info('hr.payroll.run', [
            'cycle' => "{$cycleStart}..{$cycleEnd}",
            'frequency' => $frequency,
            'employees' => $run->employee_count,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $run;
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
            // Item 13b.14 — a run is `processing` mid-recompute, so its half-written payslips
            // must not leak into YTD totals.
            ->where('hr_payroll_runs.status', 'completed')
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

        $sum = ['base' => 0.0, 'allow' => 0.0, 'holiday' => 0.0, 'ot' => 0.0, 'nd' => 0.0, 'rd' => 0.0, 'reimb' => 0.0, 'sss' => 0.0, 'ph' => 0.0, 'pg' => 0.0, 'wht' => 0.0, 'other' => 0.0, 'er_sss' => 0.0, 'er_ph' => 0.0, 'er_pg' => 0.0];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, [
            'Employee', 'Department', 'Base', 'Allowances', 'Holiday Premium', 'Overtime Pay', 'Night Diff', 'Rest Day', 'Reimbursements', 'Gross',
            'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Other Deductions', 'Total Deductions', 'Net Pay',
            'Employer SSS', 'Employer PhilHealth', 'Employer Pag-IBIG', 'Status',
        ], ',', '"', '');

        foreach ($run->payslips as $p) {
            $sum['base'] += (float) $p->base_salary;
            $sum['allow'] += (float) $p->allowances;
            $sum['holiday'] += (float) $p->holiday_premium;
            $sum['ot'] += (float) $p->overtime_pay;
            $sum['nd'] += (float) $p->night_diff_pay;
            $sum['rd'] += (float) $p->rest_day_premium;
            $sum['reimb'] += (float) $p->reimbursements;
            $sum['sss'] += (float) $p->sss;
            $sum['ph'] += (float) $p->philhealth;
            $sum['pg'] += (float) $p->pagibig;
            $sum['wht'] += (float) $p->withholding_tax;
            $sum['other'] += (float) $p->other_deductions;
            $sum['er_sss'] += (float) $p->employer_sss;
            $sum['er_ph'] += (float) $p->employer_philhealth;
            $sum['er_pg'] += (float) $p->employer_pagibig;

            fputcsv($out, [
                $p->employee->user?->name ?? $p->employee->name,
                $p->employee->department?->name,
                $p->base_salary, $p->allowances, $p->holiday_premium, $p->overtime_pay, $p->night_diff_pay, $p->rest_day_premium, $p->reimbursements, $p->gross,
                $p->sss, $p->philhealth, $p->pagibig, $p->withholding_tax, $p->other_deductions,
                $p->total_deductions, $p->net_pay,
                $p->employer_sss, $p->employer_philhealth, $p->employer_pagibig, $p->status,
            ], ',', '"', '');
        }

        fputcsv($out, [
            'TOTAL', '',
            round($sum['base'], 2), round($sum['allow'], 2), round($sum['holiday'], 2), round($sum['ot'], 2), round($sum['nd'], 2), round($sum['rd'], 2), round($sum['reimb'], 2), (float) $run->total_gross,
            round($sum['sss'], 2), round($sum['ph'], 2), round($sum['pg'], 2), round($sum['wht'], 2), round($sum['other'], 2),
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
            // Item 13b.14 — completed runs only; a mid-recompute run would skew the accrual.
            ->where('hr_payroll_runs.status', 'completed')
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
