<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LoanPayment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\SharedLeaveBalance;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\PayslipAmendment;
use App\Modules\Hr\Support\PayslipDocument;
use App\Modules\Hr\Support\PayslipLines;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\RetroFlag;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payroll engine (v2). Pay is attendance-connected — absences are deducted at the
 * daily rate from actual hr_attendance records. Statutory deductions are driven by
 * the editable Company → Benefits config + each employee's enrolment checklist
 * (ContributionService), NOT hardcoded formulas.
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
        private readonly TaxService $tax,
        private readonly AllowanceService $allowances,
    ) {}

    /**
     * The canonical daily rate, honoring the attendance policy's daily-rate basis:
     *   - fixed_divisor   → base × 12 ÷ annual_divisor (constant all year, DOLE-style)
     *   - period_workdays → this period's pay ÷ its scheduled workdays (the default)
     * Single source of truth: payslipFor() and holidayPremiumFor() both
     * price a day through here, so absence, late/undertime, and holiday premium can never
     * disagree. Default basis reproduces the number.
     */
    /**
     * Is this employee's derived daily rate below the regional minimum wage?
     *
     * @return array{
     *     daily_rate: float, basis: string, divisor: int|null, workdays: int,
     *     minimum: float|null, below: bool, flagged: bool, shortfall: float|null,
     *     compliant_salary: float|null, exempt: bool, exempt_reason: string|null
     */
    public function minimumWageCheckFor(Employee $employee, ?string $asOf = null): ?array
    {
        return $this->buildMinimumWageCheck($employee, PayrollSetting::asOf($asOf));
    }

    /**
     * The check itself, against a resolved setting and an optional explicit period. Shared so the
     * profile screen and the pre-run review can never disagree about who is below the floor.
     *
     * @param  array{start: string, end: string}|null  $period
     * @return array<string, mixed>|null
     */
    private function buildMinimumWageCheck(Employee $employee, PayrollSetting $setting, ?array $period = null): ?array
    {
        $derived = $this->derivedDailyRateFor($employee, $setting, $period);

        if ($derived === null) {
            return null;
        }

        $minimum = $setting->minimum_daily_wage !== null && (float) $setting->minimum_daily_wage > 0
            ? round((float) $setting->minimum_daily_wage, 2)
            : null;

        $below = $minimum !== null && $derived['daily_rate'] < $minimum;
        $exempt = (bool) $employee->minimum_wage_exempt;

        return [
            'daily_rate' => $derived['daily_rate'],
            'basis' => $derived['basis'],
            'divisor' => $derived['divisor'],
            'workdays' => $derived['workdays'],
            'minimum' => $minimum,
            'below' => $below,
            'flagged' => $below && ! $exempt,
            'shortfall' => $below ? round($minimum - $derived['daily_rate'], 2) : null,
            'compliant_salary' => $below ? $this->salaryClearing($minimum, $derived) : null,
            'exempt' => $exempt,
            'exempt_reason' => $employee->minimum_wage_exempt_reason,
        ];
    }

    /**
     * The derived daily rate plus the inputs that produced it, so a caller can explain the number
     * rather than just assert it. Defaults to the employee's CURRENT period; the pre-run review
     * passes the cycle it is about to pay.
     *
     * @param  array{start: string, end: string}|null  $period
     * @return array{daily_rate: float, basis: string, divisor: int|null, workdays: int, periods_per_year: int}|null
     */
    private function derivedDailyRateFor(Employee $employee, PayrollSetting $setting, ?array $period = null): ?array
    {
        $frequency = $employee->pay_frequency ?: $setting->frequency;
        $periodsPerYear = PayScheduleService::periodsPerYear($frequency);
        $period ??= $this->schedule->currentPeriodFor($frequency, $setting->anchor_date);
        $policy = $this->attendancePolicy->asOf($period['end']);

        try {
            $workingDays = $this->attendance->scheduledWorkdaysFor(
                $employee,
                $period['start'],
                $period['end'],
            );

            $daily = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays);
        } catch (ValidationException) {
            return null;
        }

        return [
            'daily_rate' => $daily,
            'basis' => $policy->daily_rate_basis,
            // Only meaningful under fixed_divisor — under period_workdays the column is still
            // populated but plays no part, and reporting it would suggest otherwise.
            'divisor' => $policy->daily_rate_basis === 'fixed_divisor' ? (int) $policy->annual_divisor : null,
            'workdays' => $workingDays,
            'periods_per_year' => $periodsPerYear,
        ];
    }

    /**
     * The monthly base salary whose derived daily rate clears $floor — dailyRateFor() inverted, so
     * the proposal is produced by the same arithmetic that produced the breach.
     *
     * @param  array{basis: string, divisor: int|null, workdays: int, periods_per_year: int}  $derived
     */
    private function salaryClearing(float $floor, array $derived): float
    {
        $monthly = $derived['basis'] === 'fixed_divisor'
            ? $floor * max(1, (int) $derived['divisor']) / 12
            : $floor * $derived['workdays'] * $derived['periods_per_year'] / 12;

        return ceil($monthly * 100) / 100;
    }

    /**
     * The employees a run for $frequency pays in this cycle.
     *
     * @return Collection<int, Employee>
     */
    public function payGroupEmployees(
        string $frequency,
        string $cycleStart,
        string $cycleEnd,
        string $defaultFrequency,
    ): Collection {
        return Employee::withTrashed()
            ->with('user')
            ->whereRaw('COALESCE(NULLIF(pay_frequency, ""), ?) = ?', [$defaultFrequency, $frequency])
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
    }

    /**
     * Everyone this run would pay whose derived daily rate falls under the floor.
     *
     * @return array{
     *     minimum: float|null, basis: string|null, divisor: int|null,
     *     cycle: array{start: string, end: string}, employees: list<array<string, mixed>>,
     *     checked: int, flagged_count: int, exempt_count: int
     */
    public function minimumWageReview(string $frequency, string $cycleStart, string $cycleEnd): array
    {
        $setting = PayrollSetting::asOf($cycleEnd);
        $period = ['start' => $cycleStart, 'end' => $cycleEnd];
        $employees = $this->payGroupEmployees($frequency, $cycleStart, $cycleEnd, $setting->frequency);

        $rows = [];
        $flagged = 0;
        $exempt = 0;
        $basis = null;
        $divisor = null;

        foreach ($employees as $employee) {
            $check = $this->buildMinimumWageCheck($employee, $setting, $period);

            if ($check === null) {
                continue;
            }

            $basis ??= $check['basis'];
            $divisor ??= $check['divisor'];

            if (! $check['below']) {
                continue;
            }

            $check['exempt'] ? $exempt++ : $flagged++;

            $rows[] = [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->user?->name,
                    'employee_no' => $employee->employee_no,
                    'job_title' => $employee->job_title,
                ],
                'base_salary' => (float) $employee->base_salary,
                ...$check,
            ];
        }

        // Worst shortfall first — the order HR would work the list in anyway.
        usort($rows, fn ($a, $b) => $b['shortfall'] <=> $a['shortfall']);

        return [
            'minimum' => $setting->minimum_daily_wage !== null ? round((float) $setting->minimum_daily_wage, 2) : null,
            'basis' => $basis,
            'divisor' => $divisor,
            'cycle' => $period,
            'employees' => $rows,
            'checked' => $employees->count(),
            'flagged_count' => $flagged,
            'exempt_count' => $exempt,
        ];
    }

    /**
     * Raise the flagged employees to the salary that clears the floor.
     *
     * @param  list<int>  $employeeIds
     * @return array{applied: list<array<string, mixed>>, skipped: list<array<string, mixed>>, back_pay_flagged: int}
     */
    public function applyMinimumWageAdjustments(
        array $employeeIds,
        string $effectiveDate,
        ?string $reason,
        bool $acknowledgeRepricing,
    ): array {
        $setting = PayrollSetting::asOf($effectiveDate);
        $note = $reason ?: 'Minimum wage compliance';

        return DB::transaction(function () use ($employeeIds, $effectiveDate, $note, $acknowledgeRepricing, $setting) {
            $applied = [];
            $skipped = [];
            $flagged = 0;

            $employees = Employee::with('user')->whereIn('id', $employeeIds)->get();

            foreach ($employees as $employee) {
                $check = $this->buildMinimumWageCheck($employee, $setting);

                // Re-checked here rather than trusted from the screen: the list may have been open
                // for a while, and raising someone who is already compliant is a raise nobody
                // decided to give.
                if ($check === null || ! $check['below'] || $check['compliant_salary'] === null) {
                    $skipped[] = [
                        'employee_id' => $employee->id,
                        'name' => $employee->user?->name,
                        'reason' => $check === null
                            ? 'No daily rate could be derived — check their work schedule.'
                            : 'Already at or above the floor.',
                    ];

                    continue;
                }

                $from = (float) $employee->base_salary;

                app(EmployeeService::class)->update($employee, [
                    'base_salary' => $check['compliant_salary'],
                    'salary_effective_date' => $effectiveDate,
                    'salary_change_reason' => $note,
                    'salary_change_type' => 'adjustment',
                    'acknowledge_repricing' => $acknowledgeRepricing,
                ]);

                // The row EmployeeService just appended — the thing the back-pay flag hangs off, so
                // resolving the difference points at the change that caused it.
                $history = SalaryHistory::where('employee_id', $employee->id)
                    ->latest('id')
                    ->first();

                if ($history !== null) {
                    $flag = app(RetroFlagService::class)->flagIfSettled(
                        $history,
                        $employee->id,
                        $effectiveDate,
                        null,
                        RetroFlag::OWED,
                        "Minimum wage adjustment to " . number_format($check['compliant_salary'], 2),
                    );

                    $flag !== null && $flagged++;
                }

                $applied[] = [
                    'employee_id' => $employee->id,
                    'name' => $employee->user?->name,
                    'from' => $from,
                    'to' => $check['compliant_salary'],
                ];
            }

            $this->audit->log(
                'updated',
                'Payroll',
                'Applied minimum-wage adjustments to ' . count($applied) . ' employee'
                    . (count($applied) === 1 ? '' : 's') . ", effective {$effectiveDate}",
            );

            return ['applied' => $applied, 'skipped' => $skipped, 'back_pay_flagged' => $flagged];
        });
    }

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
     * @param  list<array<string, mixed>>  $holidayLines  the days behind `$holidayPremium`, from
     *                                                    holidayPremiumFor(), snapshot onto the slip
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
        array $holidayLines = [],
    ): array {
        $policy ??= $this->attendancePolicy->get();
        $monthlyBase = (float) $employee->base_salary;
        // The pay for THIS period: annual salary spread across the year's periods.
        $perPeriodBase = $periodsPerYear > 0 ? round(($monthlyBase * 12) / $periodsPerYear, 2) : 0.0;
        // Daily rate via the policy basis — the one helper every pricing path uses.
        $dailyRate = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDaysInPeriod);
        $basePay = max(0, round($perPeriodBase - $unpaidDays * $dailyRate, 2));

        // Allowances are monthly, non-taxable de-minimis pay. Spread across the year's periods
        // like base, but NOT absence-prorated (they're fixed benefits, not attendance-earned).
        $pricedAsOf = $dist['cycle_end'] ?? null;
        $monthlyAllowance = $this->allowances->monthlyFor($employee, $pricedAsOf);
        // One factor, used here AND by allowanceBreakdown(), so the total and the lines
        // that explain it cannot answer the timing question differently.
        $allowanceFactor = $this->allowanceFactor($dist, $periodsPerYear);
        $perPeriodAllowance = round($monthlyAllowance * $allowanceFactor, 2);

        // PART-MONTHS. A mid-cycle hire or leaver used to receive a whole period's
        // allowance while their basic pay was prorated for the days they were actually employed.
        // Someone starting on the 25th got the full month. It is wrong on the first and last
        // payslip anybody gets — the two most closely read payslips in a career.
        $employmentFraction = 1.0;
        if (! $employee->isManagement() && isset($dist['cycle_start'], $dist['cycle_end'])) {
            $employmentFraction = $this->attendance->employmentFractionFor(
                $employee,
                (string) $dist['cycle_start'],
                (string) $dist['cycle_end'],
            );

            if ($employmentFraction < 1.0) {
                $perPeriodAllowance = round($perPeriodAllowance * $employmentFraction, 2);
            }
        }

        // Only the contributions this employee is enrolled in, at the rates in force for THIS
        // period — not whatever they are today.
        $contribConfig = $this->contributions->effectiveFor($employee, $dist['cycle_end'] ?? null);

        // Withholding tracks compensation ACTUALLY earned: the monthly-equivalent of the
        // (absence-prorated) base pay. Equals the full monthly salary when there are no
        // unpaid days, so a normal period is unchanged; it scales down only when pay is
        // reduced, so we don't over-withhold a low-earning period.
        $taxablePeriodPay = $basePay + $holidayPremium + $overtimePay + $nightDiffPay + $restDayPremium;
        $taxableMonthly = $periodsPerYear > 0 ? round($taxablePeriodPay * $periodsPerYear / 12, 2) : 0.0;

        // The two kinds of allowance are taxed differently, so split before taxing.
        // Payroll used to treat EVERY allowance as de-minimis regardless of the switch HR sets on
        // the programme, so marking one "taxable" changed nothing on the payslip and the company
        // quietly under-withheld. Gross pay is unaffected either way: the split only decides how
        // much of the same money is compensation.
        $allowanceSplit = $this->allowances->monthlyTaxBucketsFor($employee, $pricedAsOf);

        // A part-period employee is taxed on the allowance they were actually paid.
        // Without this they would be withheld on a full month of a taxable allowance they only
        // received part of, which is the same error the proration above exists to fix.
        if ($employmentFraction < 1.0) {
            $allowanceSplit = array_map(
                fn (float $v) => round($v * $employmentFraction, 2),
                $allowanceSplit,
            );
        }

        // THE STATUTORY CONTRIBUTION BASE, which is no longer one number.
        $contributableAllowance = $this->allowances->monthlyContributableFor($employee, $pricedAsOf);

        // The same part-period scaling the paid allowance gets above — an employee who was only
        // employed for half the cycle received half the allowance and is charged on half of it.
        if ($employmentFraction < 1.0) {
            $contributableAllowance = round($contributableAllowance * $employmentFraction, 2);
        }

        // `$taxablePeriodPay` is exactly the right set of earnings lines: base pay net of absences
        // and lates, plus the four premium lines. Reimbursements are excluded there because a
        // reimbursement is not income, which makes it not remuneration here either.
        // THE MONTH, NOT THIS CUTOFF DOUBLED.
        $cutoffsInMonth = max(1, (int) ($dist['count'] ?? 1));
        $isFinalCutoff = ($dist['index'] ?? 0) >= ($cutoffsInMonth - 1);
        $priorEarnings = (float) ($dist['month_to_date']['earnings'] ?? 0.0);
        $priorPeriods = (int) ($dist['month_to_date']['periods'] ?? 0);
        $missingCutoffs = max(0, $cutoffsInMonth - 1 - $priorPeriods);
        $contractedPerPeriod = $periodsPerYear > 0 ? round(($monthlyBase * 12) / $periodsPerYear, 2) : 0.0;

        $contributionBases = [
            'basic' => round($monthlyBase, 2),
            'earnings' => $isFinalCutoff
                ? round(
                    $priorEarnings
                        + $taxablePeriodPay
                        + ($missingCutoffs * $contractedPerPeriod)
                        + $contributableAllowance,
                    2,
                )
                : round(($taxablePeriodPay * $cutoffsInMonth) + $contributableAllowance, 2),
        ];

        // A taxable allowance is compensation in full — no ceiling applies to it at all. Already a
        // MONTHLY figure, which is what the withholding base is measured in.
        $taxableMonthly += $allowanceSplit['taxable'];

        // The amount above each benefit's OWN statutory ceiling is not taxed here. It
        // joins the "13th month pay and other benefits" bucket, which is exempt up to ₱90,000 a
        // year (TRAIN); only what is over that figure is compensation.
        $taxableMonthly += $this->otherBenefitsTaxableMonthly(
            $allowanceSplit['other_benefits'] * 12,
            $dist['other_benefits_annual_cap'] ?? null,
        );

        // This period's contribution to the ₱90,000 bucket, recorded on the payslip so
        // December can add up what was actually PAID during the year. Recomputing it from the
        // catalogue at year-end would let a mid-year allowance change rewrite history.
        $perPeriodOtherBenefits = $periodsPerYear > 0
            ? round(($allowanceSplit['other_benefits'] * 12) / $periodsPerYear, 2)
            : 0.0;

        // Recurring benefit shares (HMO, union dues) from Company → Benefits.
        // `count` is how many periods this calendar month has, so a MONTHLY share is split
        // the same way statutory contributions are.
        // The cycle dates are passed so an enrolment that starts or ends mid-year is charged only
        // for the periods it actually covers.
        $otherDeductions = $this->benefitDeductions->forPeriod(
            $employee,
            max(1, (int) ($dist['count'] ?? 1)),
            $dist['cycle_start'] ?? null,
            $dist['cycle_end'] ?? null,
        );

        $slip = $this->computePayslip($basePay, $reimb, $perPeriodAllowance, $contributionBases, $taxableMonthly, $contribConfig, $dist, $holidayPremium, $overtimePay, $nightDiffPay, $restDayPremium, $otherDeductions);

        // The payslip shows the FULL period basic with the absence charged as its own deduction
        // line, rather than a basic figure quietly net of it. `absence_deduction` is the charge
        // ACTUALLY applied, which is not `unpaidDays × dailyRate` whenever basePay clamped at
        // zero — deriving it at display time would then subtract more than was taken and the
        // document wouldn't add up to net pay.
        // The two figures December needs, and one it cannot derive later.
        $slip['other_benefits'] = $perPeriodOtherBenefits;
        $slip['thirteenth_month'] = 0.0; // an ordinary slip pays none

        $slip['period_base'] = round($perPeriodBase, 2);
        $slip['absence_deduction'] = round($perPeriodBase - $basePay, 2);
        $slip['allowances_breakdown'] = $this->allowanceBreakdown(
            $employee,
            $periodsPerYear,
            $perPeriodAllowance,
            $employmentFraction,
            $pricedAsOf,
            $allowanceFactor,
        );

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
        // WHICH days those were, and why. Snapshot rather than derived — see the migration:
        // an attendance correction after the run must not silently rewrite the explanation
        // printed under a figure that has not moved.
        $slip['attendance_lines'] = $unpaidDetail?->lines ?: null;
        // WHICH holidays the premium above was earned on. Snapshot for the same reason
        // `attendance_lines` is: a holiday reclassified after the run must not rewrite the
        // explanation printed under a figure that has already been paid.
        $slip['holiday_lines'] = $holidayLines ?: null;

        return $slip;
    }

    /**
     * This period's allowances split into the components that make them up, snapshot onto the
     * payslip so the document can name them instead of showing one opaque "Allowances" figure.
     *
     * @return list<array{name:string, amount:float}>
     */
    private function allowanceBreakdown(
        Employee $employee,
        int $periodsPerYear,
        float $perPeriodAllowance,
        float $employmentFraction = 1.0,
        ?string $pricedAsOf = null,
        float $allowanceFactor = 0.0,
    ): array {
        if ($periodsPerYear <= 0 || $perPeriodAllowance <= 0) {
            return [];
        }

        // The lines come from the employee's enrolled programmes, not the three
        // hard-coded `hr_employees` columns. That is what lets a fourth allowance (Gas) appear on
        // a payslip at all, and it means the names shown are the ones HR actually configured.
        $lines = [];
        foreach ($this->allowances->linesFor($employee, $pricedAsOf) as $line) {
            // Scaled by the same part-period fraction as the total, or the lines
            // would sum past it and the residual would swallow the whole proration.
            // And by the same timing factor, for the same reason.
            $perPeriod = round($line['amount'] * $allowanceFactor * $employmentFraction, 2);
            if ($perPeriod > 0) {
                $lines[] = ['name' => $line['name'], 'amount' => $perPeriod];
            }
        }

        if ($lines === []) {
            return [];
        }

        $amounts = array_column($lines, 'amount');
        $residual = round($perPeriodAllowance - array_sum($amounts), 2);
        if ($residual !== 0.0) {
            $largest = (int) array_search(max($amounts), $amounts, true);
            $lines[$largest]['amount'] = round($lines[$largest]['amount'] + $residual, 2);
        }

        return $lines;
    }

    /**
     * The taxable slice of this period's allowances, as a MONTHLY figure to match the rest of
     * the withholding base.
     */
    private function otherBenefitsTaxableMonthly(float $annualOtherBenefits, mixed $annualCap): float
    {
        // No ceiling recorded ⇒ nothing extra is taxable. The statutory figure is ₱90,000 and the
        // migration seeds it, so this is the "somebody deliberately cleared it" case.
        if ($annualCap === null || $annualOtherBenefits <= 0) {
            return 0.0;
        }

        $excess = max(0.0, $annualOtherBenefits - max(0.0, (float) $annualCap));

        return round($excess / 12, 2);
    }

    /**
     * The base salary in force at a date, per employee, from `hr_salary_histories`.
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

    /**
     * What each employee already earned, and already paid in statutory contributions, on the
     * EARLIER cutoffs of this same calendar month.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $employeeIds
     * @return array<int, array{earnings: float, sss: float, philhealth: float, pagibig: float}>
     */
    private function monthToDateContributions(PayrollRun $run, string $cycleStart, string $cycleEnd, $employeeIds): array
    {
        $monthStart = Carbon::parse($cycleEnd)->startOfMonth()->toDateString();

        return Payslip::query()
            ->whereIn('hr_payslips.employee_id', $employeeIds)
            ->where('hr_payslips.payroll_run_id', '!=', $run->id)
            ->whereHas('payrollRun', fn ($q) => $q
                ->where('type', $run->type)
                ->whereDate('cycle_end', '>=', $monthStart)
                ->whereDate('cycle_end', '<=', $cycleEnd)
                ->whereDate('cycle_start', '<', $cycleStart))
            ->groupBy('hr_payslips.employee_id')
            ->selectRaw(
                'hr_payslips.employee_id,'
                // The same set of lines `$taxablePeriodPay` is built from — base pay net of
                // absences and lates, plus the four premium lines. Allowances are NOT here:
                // the contributable share is added as a monthly figure, once, by the caller.
                .' SUM(hr_payslips.base_salary + hr_payslips.holiday_premium + hr_payslips.overtime_pay'
                .' + hr_payslips.night_diff_pay + hr_payslips.rest_day_premium) AS earnings_prior,'
                .' SUM(hr_payslips.sss) AS sss_prior,'
                .' SUM(hr_payslips.philhealth) AS philhealth_prior,'
                .' SUM(hr_payslips.pagibig) AS pagibig_prior,'
                // How many earlier cutoffs actually contributed. A cutoff that was never run
                // leaves a hole in the month, and a hole must be filled from the contract rather
                // than counted as zero earnings — see payslipFor().
                .' COUNT(*) AS periods_prior'
            )
            ->get()
            ->keyBy('employee_id')
            ->map(fn ($r) => [
                'earnings' => (float) $r->earnings_prior,
                'sss' => (float) $r->sss_prior,
                'philhealth' => (float) $r->philhealth_prior,
                'pagibig' => (float) $r->pagibig_prior,
                'periods' => (int) $r->periods_prior,
            ])
            ->all();
    }

    /** Snapshot format. v1 was a flat map of 13 attendance-policy fields; v2 is grouped. */
    private const SNAPSHOT_VERSION = 2;

    /**
     * Every setting that priced this run, captured onto it so a later config change can never
     * make an old payslip inexplicable (4b, completed in).
     */
    private function policySnapshot(PayrollSetting $setting, string $cycleEnd): array
    {
        return [
            'version' => self::SNAPSHOT_VERSION,
            'captured_at' => now()->toIso8601String(),
            // asOf(), not get(): the snapshot must record the rules that PRICED this run, and
            // since the policy became dated those are the ones in force at the cycle end — not
            // whatever the singleton says at the moment the snapshot is written.
            'attendance_policy' => $this->attendancePolicy->asOf($cycleEnd)
                ->only(array_keys(AttendancePolicyService::DEFAULTS)),
            // The dated rate versions actually in force for this cycle. The
            // `contributions` block below is the enrolment config; this is what priced it.
            'contribution_versions' => $this->contributions->versionsAsOf($cycleEnd)
                ->map(fn ($v) => $v->only([
                    'type', 'basis', 'employee_rate', 'employer_rate', 'salary_floor', 'salary_cap',
                    'employee_amount_cap', 'employer_amount_cap', 'fixed_employer_add_on', 'add_on_min_salary',
                ]) + [
                    'effective_from' => $v->effective_from?->toDateString(),
                    // The bands too. Without them a bracket contribution's snapshot
                    // records the RATE but not the table it multiplies, so nothing can say
                    // afterwards which Monthly Salary Credit an SSS deduction was charged on —
                    // the first thing anyone asks when they query the figure.
                    'brackets' => $v->basis === 'bracket'
                        ? $v->brackets->map->only(['min_salary', 'msc', 'employer_add_on'])->values()->all()
                        : [],
                ])
                ->values()
                ->all(),
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
            'payroll_setting' => $setting->only($setting->getFillable()),
        ];
    }

    /**
     * Refuse to drop a payslip that a PAYMENT still points at.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $keep  employee ids this run still pays
     * @throws ValidationException naming every blocked employee and the batches involved
     */
    private function assertDroppable(PayrollRun $run, \Illuminate\Support\Collection $keep, string $frequency): void
    {
        $blocked = $run->payslips()
            ->whereNotIn('employee_id', $keep)
            ->where('status', 'computed')
            ->whereHas('payments', fn ($q) => $q->withTrashed())
            ->with(['employee.user', 'payments' => fn ($q) => $q->withTrashed()->with('batch')])
            ->get();

        if ($blocked->isEmpty()) {
            return;
        }

        $names = $blocked
            ->map(fn (Payslip $p) => $p->employee?->user?->name ?? "Employee #{$p->employee_id}")
            ->unique()
            ->values();

        $batches = $blocked
            ->flatMap(fn (Payslip $p) => $p->payments->map(fn ($pay) => $pay->batch?->reference))
            ->filter()
            ->unique()
            ->values();

        $groupLabel = str_replace('_', '-', $frequency);

        throw ValidationException::withMessages([
            'payroll' => [
                $names->implode(', ')
                    . ($names->count() === 1 ? ' has' : ' have')
                    . ' payments queued from this cycle'
                    . ($batches->isEmpty() ? '' : ' in ' . $batches->implode(', '))
                    . ", but are no longer in the {$groupLabel} pay group, so re-running would "
                    . 'delete the payslips those payments are for. Give them an explicit pay '
                    . 'frequency to put them back in this group, or settle and remove those '
                    . 'payments first, then run again.',
            ],
        ]);
    }

    /**
     * Which captured rules NO LONGER match what would price this cycle today.
     *
     * @return list<array{group: string, key: string, was: mixed, now: mixed}>
     */
    public function snapshotDrift(PayrollRun $run): array
    {
        $snapshot = $run->policy_snapshot;

        // v1 runs stored a flat map with no groups; there is nothing coherent to compare.
        if (! is_array($snapshot) || ($snapshot['version'] ?? 1) < 2) {
            return [];
        }

        $cycleEnd = $run->cycle_end?->toDateString();

        if ($cycleEnd === null) {
            return [];
        }

        $current = [
            'attendance_policy' => $this->attendancePolicy->asOf($cycleEnd)
                ->only(array_keys(AttendancePolicyService::DEFAULTS)),
            'payroll_setting' => PayrollSetting::asOf($cycleEnd)->only((new PayrollSetting)->getFillable()),
            'contributions' => $this->contributions->versionsAsOf($cycleEnd)
                ->mapWithKeys(fn ($v) => [$v->type => [
                    'basis' => $v->basis,
                    'employee_rate' => $v->employee_rate,
                    'employer_rate' => $v->employer_rate,
                    'effective_from' => $v->effective_from?->toDateString(),
                ]])
                ->all(),
        ];

        $was = [
            'attendance_policy' => $snapshot['attendance_policy'] ?? [],
            'payroll_setting' => $snapshot['payroll_setting'] ?? [],
            'contributions' => collect($snapshot['contribution_versions'] ?? [])
                ->mapWithKeys(fn ($v) => [$v['type'] => [
                    'basis' => $v['basis'] ?? null,
                    'employee_rate' => $v['employee_rate'] ?? null,
                    'employer_rate' => $v['employer_rate'] ?? null,
                    'effective_from' => $v['effective_from'] ?? null,
                ]])
                ->all(),
        ];

        $drift = [];

        foreach ($current as $group => $fields) {
            foreach ($fields as $key => $now) {
                // A key absent from the snapshot is a setting added after the run, not a change
                // to one — reporting it would flag every run predating a new column.
                if (! array_key_exists($key, $was[$group])) {
                    continue;
                }

                if (in_array($key, self::DRIFT_IGNORED, true)) {
                    continue;
                }

                $before = $was[$group][$key];

                if (self::sameValue($before, $now)) {
                    continue;
                }

                $drift[] = ['group' => $group, 'key' => $key, 'was' => $before, 'now' => $now];
            }
        }

        return $drift;
    }

    /**
     * Captured for the record, but incapable of re-pricing a payslip — so listing them as
     * changes on a panel headed "what priced these payslips" is noise that buries the fields
     * that DO move money (`violation_rules` alone is a four-category ladder that renders longer
     * than the rest of the panel put together).
     */
    private const DRIFT_IGNORED = [
        'violation_rules', 'task_format',
        'geofence_enabled', 'office_lat', 'office_lng', 'office_radius_m', 'office_ips',
    ];

    /** Whether two captured values mean the same thing to the engine. */
    private static function sameValue(mixed $before, mixed $now): bool
    {
        $empty = fn (mixed $v) => $v === null || $v === 0 || $v === 0.0 || $v === '0';

        if ($empty($before) && $empty($now)) {
            return true;
        }

        return json_encode($before) === json_encode($now);
    }

    /**
     * How much of the MONTHLY allowance lands on this period.
     *
     * @param  array<string, mixed>  $dist
     */
    private function allowanceFactor(array $dist, int $periodsPerYear): float
    {
        $timing = (string) ($dist['allowance_timing'] ?? 'every');
        $index = (int) ($dist['index'] ?? 0);
        $count = (int) ($dist['count'] ?? 1);

        return match ($timing) {
            'first' => $index === 0 ? 1.0 : 0.0,
            'last' => $index >= $count - 1 ? 1.0 : 0.0,
            default => $periodsPerYear > 0 ? 12 / $periodsPerYear : 0.0,
        };
    }

    /**
     * What this cutoff actually collects for one statutory contribution.
     *
     * @param  array<string, mixed>  $dist
     */
    private function contributionForPeriod(string $type, float $monthly, float $factor, array $dist): float
    {
        $isFinalCutoff = ($dist['index'] ?? 0) >= (($dist['count'] ?? 1) - 1);

        if (! $isFinalCutoff) {
            return round($monthly * $factor, 2);
        }

        $alreadyTaken = (float) ($dist['month_to_date'][$type] ?? 0.0);

        return round($monthly - $alreadyTaken, 2);
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
     * `allowances` the non-taxable de-minimis pay that go into gross; `contribBases` are the
     * candidate monthly salary figures statutory CONTRIBUTIONS can be computed on, and each
     * contribution's own version says which of them it wants; `taxableMonthly` is the
     * monthly-equivalent compensation WITHHOLDING TAX is computed on (scales with actual
     * earnings); `contribConfig` is the enrolled contributions; `overrides` are per-employee
     * statutory amounts that win over config.
     *
     * @param  array{basic: float, earnings: float}  $contribBases
     * @param  array<string, array<string, mixed>>  $contribConfig
     * @param  array{index:int, count:int, deduction_timing:string, tax_timing:string}  $dist
     * @return array<string, mixed>
     */
    public function computePayslip(float $basePay, float $reimb, float $allowances, array $contribBases, float $taxableMonthly, array $contribConfig, array $dist, float $holidayPremium = 0.0, float $overtimePay = 0.0, float $nightDiffPay = 0.0, float $restDayPremium = 0.0, array $otherDeductions = ['total' => 0.0, 'lines' => []]): array
    {
        // Each contribution is charged on the figure ITS OWN version asks for, which
        // is why this is resolved per type rather than once. PhilHealth stays on basic salary by
        // law while SSS follows earnings, and that is the recommended setup, not an edge case.
        $sssBase = $this->contributionBaseFor('sss', $contribConfig, $contribBases);
        $phBase = $this->contributionBaseFor('philhealth', $contribConfig, $contribBases);
        $pgBase = $this->contributionBaseFor('pagibig', $contribConfig, $contribBases);

        // SSS/PhilHealth/Pag-IBIG are MONTHLY amounts (on a monthly base); the
        // timing setting decides which cutoff(s) in the month they land on.
        $sssM = $this->monthlyContribution('sss', $contribConfig, $sssBase);
        $phM = $this->monthlyContribution('philhealth', $contribConfig, $phBase);
        $pgM = $this->monthlyContribution('pagibig', $contribConfig, $pgBase);

        $cf = $this->timingFactor($dist['deduction_timing'] ?? 'every', $dist['index'] ?? 0, $dist['count'] ?? 1);
        $sss = $this->contributionForPeriod('sss', $sssM, $cf, $dist);
        $philhealth = $this->contributionForPeriod('philhealth', $phM, $cf, $dist);
        $pagibig = $this->contributionForPeriod('pagibig', $pgM, $cf, $dist);

        // Employer counterpart of each contribution (config `employer_rate`). Same
        // enrolment, base and timing as the employee side, but NOT deducted from pay — tracked
        // only for government remittance (see the Payroll report / run CSV).
        $erSss = round($this->monthlyEmployerContribution('sss', $contribConfig, $sssBase) * $cf, 2);
        $erPh = round($this->monthlyEmployerContribution('philhealth', $contribConfig, $phBase) * $cf, 2);
        $erPg = round($this->monthlyEmployerContribution('pagibig', $contribConfig, $pgBase) * $cf, 2);

        // Withholding tax on the taxable monthly compensation actually earned (minus the
        // employee's mandatory contributions), distributed by the tax-timing setting. What the
        // caller put into `taxableMonthly`: base pay + all four premium lines, plus
        // any allowance above the de-minimis ceiling. Reimbursements are never in it.
        $annualTaxable = max(0, ($taxableMonthly - ($sssM + $phM + $pgM)) * 12);
        $annualTax = $this->annualWithholdingTax($annualTaxable, $dist['cycle_end'] ?? null);
        $monthlyTax = $annualTax / 12;
        $tf = $this->timingFactor($dist['tax_timing'] ?? 'every', $dist['index'] ?? 0, $dist['count'] ?? 1);
        $wht = round($monthlyTax * $tf, 2);

        // The derivation behind that one figure, snapshot for the payslip.
        $taxBasis = $wht == 0.0 ? null : [
            'taxable_monthly' => round($taxableMonthly, 2),
            'contributions_monthly' => round($sssM + $phM + $pgM, 2),
            'annual_taxable' => round($annualTaxable, 2),
            'annual_tax' => round($annualTax, 2),
            // The band it landed in, from the table in force for THIS cycle — so a payslip keeps
            // citing the brackets it was priced under after HR adds a new tax version.
            'band' => $this->tax->bandFor($annualTaxable, $dist['cycle_end'] ?? null),
            'monthly_tax' => round($monthlyTax, 2),
            // How the month's tax was split across this period's cutoffs (1 = the whole month
            // lands here; 0.5 = half on each of two).
            'timing_factor' => round($tf, 4),
            'timing' => $dist['tax_timing'] ?? 'every',
        ];
        $taxAnnualisation = 0.0;
        // Populated only on the cycle that settles a tax year; null everywhere else, so
        // the payslip renders the settlement block exactly when it has the figures for it.
        $taxAnnualisationBasis = null;

        // THIS PERIOD's taxable compensation, net of the employee's own contributions.
        // Stored per slip because the year's total is what the tax on 13th month is computed
        // against, and it must be a sum of what happened rather than a projection: twelve
        // annualised projections of a year are not a year.
        $periodsPerYear = max(1, (int) ($dist['periods_per_year'] ?? (($dist['count'] ?? 1) * 12)));
        $taxablePay = round($annualTaxable / $periodsPerYear, 2);

        // YEAR-END TRUE-UP. On the cycle containing 31 December, stop projecting and
        // settle the year: tax actually due on what was actually earned, minus everything already
        // withheld. The caller supplies the prior totals; this is the only place that can apply
        // them, because it is the only place that knows this period's own taxable share.
        if (isset($dist['year_end_true_up'])) {
            $priorTaxable = (float) ($dist['year_end_true_up']['taxable_prior'] ?? 0);
            $priorWithheld = (float) ($dist['year_end_true_up']['withheld_prior'] ?? 0);

            $annualDue = $this->annualWithholdingTax($priorTaxable + $taxablePay, $dist['cycle_end'] ?? null);
            $settled = round($annualDue - $priorWithheld, 2);

            // Recorded as the DIFFERENCE from what this period would otherwise have withheld, so
            // the payslip can name the adjustment rather than show an unexplained December figure.
            $taxAnnualisation = round($settled - $wht, 2);
            $wht = $settled;

            // And the three figures that EXPLAIN it, captured here because they are
            // assembled from every other payslip of the year and would otherwise be gone. Without
            // them December shows a negative Withholding Tax and a net larger than gross with
            // nothing to say why.
            $taxAnnualisationBasis = [
                'year' => (int) Carbon::parse($dist['cycle_start'] ?? $dist['cycle_end'] ?? now())->year,
                'year_taxable' => round($priorTaxable + $taxablePay, 2),
                'annual_tax_due' => round($annualDue, 2),
                'previously_withheld' => round($priorWithheld, 2),
                // What this payslip ends up withholding once the year is settled. Negative is a
                // refund, and the document says so in words rather than leaving a minus sign.
                'settled_this_period' => $settled,
            ];
        }

        // All four premium lines are in gross AND in the withholding base — the
        // caller folds them into `taxableMonthly` before calling.
        $gross = round($basePay + $allowances + $reimb + $holidayPremium + $overtimePay + $nightDiffPay + $restDayPremium, 2);

        // The employee's share of the benefits they're enrolled in (HMO, union
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

        // And now say WHICH obligation went short. Capping the total while leaving the
        // components at their computed values made the payslip disagree with itself, and the
        // remittance report sums those components straight out of the table: it reported more
        // withheld than was ever taken. Allocate the cap across them so the parts sum to the total.
        $allocation = $this->allocateDeductionCap(
            ['sss' => $sss, 'philhealth' => $philhealth, 'pagibig' => $pagibig, 'withholding_tax' => $wht, 'other_deductions' => $otherTotal],
            $gross,
            $deductionCarryover,
        );
        ['collected' => $collected, 'uncollected' => $uncollected] = $allocation;

        $sss = $collected['sss'];
        $philhealth = $collected['philhealth'];
        $pagibig = $collected['pagibig'];
        $wht = $collected['withholding_tax'];
        $otherTotal = $collected['other_deductions'];

        // Recover what earlier periods could not collect, from whatever room is left.
        $recovered = [];
        $arrearsRecovered = 0.0;
        $room = round($gross - $totalDeductions, 2);

        if ($room > 0 && ! empty($dist['arrears'])) {
            // Statutory first, then tax, then benefit shares — the priority the audit specifies.
            // Loans are not here: they are collected after the payslip by LoanService, which has
            // its own ledger and its own shortfall rule.
            foreach (['sss', 'philhealth', 'pagibig', 'withholding_tax', 'other_deductions'] as $key) {
                $owed = round((float) ($dist['arrears'][$key] ?? 0), 2);
                if ($owed <= 0 || $room <= 0) {
                    continue;
                }

                $take = round(min($owed, $room), 2);
                $recovered[$key] = $take;
                $arrearsRecovered = round($arrearsRecovered + $take, 2);
                $room = round($room - $take, 2);
            }
        }

        if ($recovered !== []) {
            // Added to the components themselves: the remittance report sums those columns, and
            // money recovered this period is genuinely remitted this period. The breakdown below
            // preserves which part was an arrear for anyone who needs the distinction.
            $sss = round($sss + ($recovered['sss'] ?? 0), 2);
            $philhealth = round($philhealth + ($recovered['philhealth'] ?? 0), 2);
            $pagibig = round($pagibig + ($recovered['pagibig'] ?? 0), 2);
            $wht = round($wht + ($recovered['withholding_tax'] ?? 0), 2);
            $otherTotal = round($otherTotal + ($recovered['other_deductions'] ?? 0), 2);
            $totalDeductions = round($totalDeductions + $arrearsRecovered, 2);
        }

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
            // How much of `withholding_tax` is the year-end correction. Signed:
            // positive collected, negative refunded. Zero on every period but the last.
            'tax_annualisation' => $taxAnnualisation,
            // The year's figures behind that adjustment, so the payslip can show its
            // derivation instead of one unexplained (and often negative) tax line.
            'tax_annualisation_basis' => $taxAnnualisationBasis,
            // How `withholding_tax` was arrived at. Null when none was withheld.
            'tax_basis' => $taxBasis,
            'taxable_pay' => $taxablePay,
            // Recurring benefit shares, with the breakdown so the payslip can
            // name them rather than show one opaque figure.
            'other_deductions' => $otherTotal,
            'other_deductions_breakdown' => $otherDeductions['lines'] ?? [],
            'employer_sss' => $erSss,
            'employer_philhealth' => $erPh,
            'employer_pagibig' => $erPg,
            //  Recorded which of the three came from a per-employee amount rather than the
            // government rates. Per-employee amounts are gone — every contribution now follows the
            // company rates for everyone — so a new payslip has nothing to declare here. The column
            // and its readers stay: payslips issued while the override existed must keep saying so,
            // or the remittance mismatch they carry becomes invisible after the fact.
            'contribution_overrides' => null,
            // What each contribution was actually charged on, snapshot because it can
            // no longer be re-derived: the earnings basis depends on this period's attendance and
            // premium lines, and on a per-allowance `contributable` flag HR can change afterwards.
            // Only the types the employee is enrolled in — the others were charged on nothing.
            'contribution_bases' => array_filter([
                'sss' => isset($contribConfig['sss']) ? $sssBase : null,
                'philhealth' => isset($contribConfig['philhealth']) ? $phBase : null,
                'pagibig' => isset($contribConfig['pagibig']) ? $pgBase : null,
            ], fn ($v) => $v !== null) ?: null,
            'total_deductions' => $totalDeductions,
            // What couldn't be taken because gross wasn't enough to cover it (item 1).
            'deduction_carryover' => $deductionCarryover,
            // The same figure split by obligation, so the arrears can be named. Null
            // when nothing went short, which is the overwhelming majority of payslips.
            'deduction_carryover_breakdown' => $uncollected ?: null,
            // What this payslip recovered from EARLIER periods' shortfalls. Subtracted
            // from the outstanding balance by `deductionArrearsFor()`, which derives it rather
            // than keeping a running total that a re-run could double-count.
            'arrears_recovered' => $arrearsRecovered,
            'arrears_recovered_breakdown' => $recovered ?: null,
            // round() last so this is always a float — max(0, …) returns int 0 on an
            // exact zero, which made net_pay the only money field with a shifting type.
            'net_pay' => round(max(0, $gross - $totalDeductions), 2),
        ];
    }

    /**
     * Outstanding deduction arrears per employee, per obligation.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>|array<int, int>  $employees
     * @return array<int, array<string, float>>  employee_id => [obligation => outstanding]
     */
    public function deductionArrearsFor($employees, ?int $excludeRunId = null): array
    {
        $ids = is_array($employees) ? $employees : $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('hr_payslips')
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->whereNull('hr_payslips.deleted_at')
            ->whereNull('hr_payroll_runs.deleted_at')
            ->where('hr_payroll_runs.type', 'regular')
            ->whereIn('hr_payslips.employee_id', $ids)
            ->when($excludeRunId !== null, fn ($q) => $q->where('hr_payslips.payroll_run_id', '!=', $excludeRunId))
            // Only the payslips that moved a balance either way.
            ->where(fn ($q) => $q->where('hr_payslips.deduction_carryover', '>', 0)
                ->orWhere('hr_payslips.arrears_recovered', '>', 0))
            ->get(['hr_payslips.employee_id', 'hr_payslips.deduction_carryover_breakdown', 'hr_payslips.arrears_recovered_breakdown']);

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->employee_id;

            foreach ((array) json_decode($row->deduction_carryover_breakdown ?? '[]', true) as $key => $amount) {
                $out[$id][$key] = round(($out[$id][$key] ?? 0) + (float) $amount, 2);
            }

            foreach ((array) json_decode($row->arrears_recovered_breakdown ?? '[]', true) as $key => $amount) {
                $out[$id][$key] = round(($out[$id][$key] ?? 0) - (float) $amount, 2);
            }
        }

        // Drop anything fully settled, so callers can treat a present key as a live debt. Rounding
        // can leave a stray centavo either way; below one is nothing to chase.
        foreach ($out as $id => $obligations) {
            $live = [];
            foreach ($obligations as $key => $amount) {
                if ($amount >= 0.01) {
                    $live[$key] = (float) round($amount, 2);
                }
            }
            $out[$id] = $live;
        }

        return array_filter($out, fn ($o) => $o !== []);
    }

    /**
     * Split a capped deduction total across the obligations it is made of.
     *
     * @param  array<string, float>  $components  obligation => computed amount
     * @param  float  $gross  the ceiling on what can be taken
     * @param  float  $carryover  the aggregate shortfall already computed by the caller
     * @return array{collected: array<string, float>, uncollected: array<string, float>}
     */
    private function allocateDeductionCap(array $components, float $gross, float $carryover): array
    {
        // Nothing went short: every component is collected in full. Returned before any arithmetic
        // so the ordinary payslip — which is almost all of them — is bit-for-bit what it was
        // before this method existed, rather than round-tripped through an allocation that can
        // only reproduce the same numbers.
        if ($carryover <= 0) {
            return ['collected' => $components, 'uncollected' => []];
        }

        // Refunds and any other negative are honoured whole and increase what is available.
        $pool = $gross;
        foreach ($components as $amount) {
            if ($amount < 0) {
                $pool -= $amount; // subtracting a negative — the refund enlarges the pool
            }
        }

        $collected = [];
        $uncollected = [];

        foreach ($components as $key => $amount) {
            if ($amount <= 0) {
                $collected[$key] = $amount;

                continue;
            }

            $take = round(min($amount, max(0, $pool)), 2);
            $short = round($amount - $take, 2);

            $collected[$key] = $take;
            if ($short > 0) {
                $uncollected[$key] = $short;
            }

            $pool = round($pool - $take, 2);
        }

        return ['collected' => $collected, 'uncollected' => $uncollected];
    }

    /**
     * The monthly salary figure one contribution is charged on.
     *
     * @param  array<string, array<string, mixed>>  $config
     * @param  array{basic: float, earnings: float}  $bases
     */
    private function contributionBaseFor(string $type, array $config, array $bases): float
    {
        $basis = $config[$type]['salary_basis'] ?? 'basic';

        return round((float) ($bases[$basis] ?? $bases['basic'] ?? 0.0), 2);
    }

    /**
     * The monthly amount for one statutory contribution: 0 if the employee isn't enrolled, else
     * the company-config computation.
     *
     * @param  array<string, array<string, mixed>>  $config
     */
    private function monthlyContribution(string $type, array $config, float $base): float
    {
        if (! isset($config[$type])) {
            return 0.0; // not enrolled → no deduction
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

        return ContributionCalculator::employer($config[$type], $base);
    }

    /**
     * One contribution from its config — the employee side.
     *
     * @param  array<string, mixed>  $config
     */
    private function contribution(array $config, float $base): float
    {
        return ContributionCalculator::employee($config, $base);
    }

    /**
     * Premium pay for actually working on a (non-working) holiday in the cycle. The
     * employee's daily rate × the statutory premium on top of the normal day's pay:
     * regular holiday +100%, special non-working +30% (special_working = normal day,
     * no premium). Only days with a present/late attendance record earn it. Simplified:
     * flat daily-rate basis, no OT/night-diff stacking.
     *
     * Returns the money AND the days behind it. The lines are what the payslip prints under
     * the Holiday figure: before this the method returned a bare float, so a slip that paid
     * two holidays showed one number the employee had no way to check.
     *
     * @param  array<string, string>  $holidayMap  date 'Y-m-d' => type
     * @return array{pay: float, lines: list<array{date: string, name: string|null, type: string, rate_pct: int, rest_day: bool, daily_rate: float, amount: float}>}
     */
    private function holidayPremiumFor(Employee $employee, string $start, string $end, int $periodsPerYear, int $workingDays, array $holidayMap, ?AttendancePolicy $policy = null): array
    {
        $none = ['pay' => 0.0, 'lines' => []];

        if ($workingDays <= 0 || $holidayMap === []) {
            return $none;
        }

        // Same daily-rate basis as absence/late — priced through the shared helper.
        $policy ??= $this->attendancePolicy->get();

        // Management staff are on a FIXED salary, so by default a holiday they worked pays no
        // premium — the day was already inside the salary. `management_holiday_premium`
        // (Company → Rules) buys that back: on, they earn the same statutory premium as
        // everyone else. Read off the CYCLE'S policy version, so a rule changed today cannot
        // reprice a period that has already been paid.
        if ($employee->isManagement() && ! $policy->management_holiday_premium) {
            return $none;
        }

        $dailyRate = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays);

        $worked = array_flip($this->attendance->workedDatesBetween($employee, $start, $end));

        // Item 4 — when the holiday falls on a REST day the salary never covered that day at
        // all (the workdays-only basis spreads pay over scheduled workdays), so the whole rate
        // is owed, not just the add-on. Mirrors $salaryCoversRestDays in restDayPremiumFor().
        $tz = config('app.timezone_local');
        $salaryCoversEveryDay = $policy->daily_rate_basis === 'fixed_divisor'
            && (int) $policy->annual_divisor >= 365;

        // The holiday's NAME, for the line the payslip prints. One memoized query for the whole
        // run, not one per employee.
        $names = $this->holidays->namesBetween(Carbon::parse($start, $tz), Carbon::parse($end, $tz));

        $premium = 0.0;
        $lines = [];
        // Ordered so the printed lines read down the period rather than in whatever order the
        // holiday table came back in.
        ksort($holidayMap);

        foreach ($holidayMap as $date => $type) {
            if (! isset($worked[$date])) {
                continue;
            }

            $multiplier = self::holidayPremiumMultiplier($type, $policy);
            $onRestDay = false;

            if (! $salaryCoversEveryDay) {
                $isWorkday = (bool) ($this->workSchedule->effectiveFor($employee, Carbon::parse($date, $tz))['is_workday'] ?? true);
                if (! $isWorkday && $multiplier > 0) {
                    // Add-on → full rate: +100% becomes 200%, +30% becomes 130%.
                    $multiplier += 1.0;
                    $onRestDay = true;
                }
            }

            $amount = round($dailyRate * $multiplier, 2);
            $premium += $dailyRate * $multiplier;

            // A day that earned nothing earns no line either — a `special_working` day inside the
            // cycle is an ordinary working day, and printing it at ₱0 under "Holiday" would read
            // as a holiday the employee was short-changed on.
            if ($amount == 0.0) {
                continue;
            }

            $lines[] = [
                'date' => $date,
                'name' => $names[$date] ?? null,
                'type' => (string) $type,
                // The POLICY percentage as HR set it, not the add-on this method works in: "200%"
                // is the figure in Company → Rules and the one the employee can look up.
                'rate_pct' => self::dayRateMultiplierPct($type, ! $onRestDay, $policy),
                'rest_day' => $onRestDay,
                'daily_rate' => round($dailyRate, 2),
                'amount' => $amount,
            ];
        }

        return ['pay' => round($premium, 2), 'lines' => $lines];
    }

    /**
     * The add-on multiple of the daily rate for WORKING a holiday of this type. Reads the
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
     * Overtime pay for the cycle: each APPROVED overtime request's hours × the hourly rate ×
     * the OT rate for that day's type (from the editable rate table). Stacks with holiday premium
     * WITHOUT double-counting — the premium pays the regular-day hours worked on a holiday, OT pays
     * the *extra* approved hours. Management → 0. Paid only in `approved_only` mode (auto detection is
     * `none` = off). No-op until an OT request is approved, so wiring it changes no pesos today.
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

        // WHICH minutes count is the mode's job; pricing them is shared below.
        // approved_only        → the hours on each APPROVED request (the safe, authorised default)
        // auto_after_threshold → clock minutes past the scheduled end (opt-in, warned in the UI)
        // none                 → overtime is switched off entirely
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
     * Night-shift differential: +`night_diff_pct`% of the hourly rate for every minute worked
     * between 22:00 and 06:00 — for ALL night work, not just overtime, per PH practice. Management → 0.
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
        $nightByDate = $this->attendance->nightMinutesFor($employee, $start, $end, $policy);
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

            // Needed by BOTH branches now: the ordinary-hours rate is what the plain differential
            // is a percentage of, and on a rest day or holiday that is not 100%.
            $isWorkday = (bool) ($this->workSchedule->effectiveFor($employee, Carbon::parse($date, $tz))['is_workday'] ?? true);
            $holidayType = $holidayMap[$date] ?? null;

            if ($plainNight > 0) {
                $dayRate = self::dayRateMultiplierPct($holidayType, $isWorkday, $policy) / 100;
                $pay += ($plainNight / 60) * $hourly * $dayRate * $nightRate;
            }
            if ($otNight > 0) {
                $otRate = self::overtimeMultiplierPct($holidayType, $isWorkday, $policy) / 100;
                $pay += ($otNight / 60) * $hourly * $otRate * $nightRate;
            }
            $minutes += $nightMins;
        }

        return ['pay' => round($pay, 2), 'minutes' => $minutes];
    }

    /** A full 8-hour day in minutes — the line between rest-day premium and rest-day overtime. */
    private const REST_DAY_PREMIUM_CAP_MIN = 8 * 60;

    /**
     * Rest-day premium: pay for WORKING a scheduled rest day — the first 8 hours at
     * `premium_rest_day_pct`. Hours past the 8th are rest-day OVERTIME instead (see the cap in
     * overtimeMinutesByDate), so one rest day is never paid through both lines.
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
     * @return array<string, array{minutes:int, night:int, windows?:array<int, array{start:int, end:int}>, legacy_minutes?:int}>
     */
    private function overtimeMinutesByDate(Employee $employee, string $start, string $end, AttendancePolicy $policy, array $restByDate = []): array
    {
        $map = match ($policy->overtime_mode) {
            'approved_only' => $this->approvedOvertimeMinutes($employee, $start, $end, $policy),
            'auto_after_threshold' => $this->autoOvertimeMinutes($employee, $start, $end, $policy),
            default => [],
        };

        // On a worked rest day the first 8 hours are the rest-day premium, so only the excess can be
        // overtime. Approved OT already requires a punch-backed window; this remains a final guard
        // shared with auto mode.
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
     * Auto-mode overtime, capped, with the over-cap minutes payable only on an approval.
     *
     * @return array<string, array{minutes:int, night:int}>
     */
    private function autoOvertimeMinutes(Employee $employee, string $start, string $end, AttendancePolicy $policy): array
    {
        $map = $this->attendance->autoOvertimeMinutesFor(
            $employee,
            $start,
            $end,
            (int) $policy->overtime_threshold_minutes,
            (int) $policy->auto_overtime_daily_cap_minutes,
            $policy,
        );

        [$nightStart, $nightEnd] = $this->attendance->nightWindow($policy);
        $map = AttendanceService::applyAutoOvertimePeriodCap(
            $map,
            (int) $policy->auto_overtime_period_cap_minutes,
            $nightStart,
            $nightEnd,
        );

        $withExcess = array_filter($map, fn (array $e) => ($e['excess_minutes'] ?? 0) > 0);
        if ($withExcess === []) {
            return $map; // nothing was held back, so nothing needs releasing
        }

        // Only now is the approved-request query worth running.
        $approved = $this->approvedOvertimeMinutes($employee, $start, $end, $policy);

        foreach (array_keys($withExcess) as $date) {
            $map[$date] = AttendanceService::releaseApprovedOvertimeEntry(
                $map[$date],
                $approved[$date] ?? null,
                $nightStart,
                $nightEnd,
            );
        }

        return $map;
    }

    /**
     * Approved overtime as the exact intersection of approved and punch-backed windows. Legacy
     * hours-only rows retain a capacity-limited total and cannot prove a night portion.
     *
     * @return array<string, array{minutes:int, night:int, windows:array<int, array{start:int, end:int}>, legacy_minutes:int}>
     */
    private function approvedOvertimeMinutes(Employee $employee, string $start, string $end, AttendancePolicy $policy): array
    {
        return $this->attendance->approvedOvertimeMinutesFor($employee, $start, $end, null, $policy);
    }

    /**
     * The OT rate (whole percent) for a day, from the editable rate table: regular holiday and
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
     * The ORDINARY-HOURS rate (whole percent) for a day — the sibling of overtimeMultiplierPct(),
     * and the base the night differential is a percentage of (item 9).
     */
    public static function dayRateMultiplierPct(?string $holidayType, bool $isWorkday, AttendancePolicy $policy): int
    {
        return match ($holidayType) {
            'regular' => (int) $policy->premium_regular_holiday_pct,
            'special_non_working' => (int) $policy->premium_special_day_pct,
            default => $isWorkday
                ? 100
                : (int) $policy->premium_rest_day_pct, // rest day (non-workday, non-holiday)
        };
    }

    /**
     * TRAIN annual graduated income tax (2023 onwards). We tax the annualised
     * period pay and divide back down, so withholding is correct for ANY pay
     * frequency (weekly, semi-monthly, monthly…) from one table.
     */
    private function annualWithholdingTax(float $t, ?string $asOf = null): float
    {
        // The table now comes from `hr_tax_versions`, resolved as of the cycle end, the
        // same way salaries, statutory rates, allowances and the attendance policy already are.
        // The brackets used to be a `match (true)` literal here: correct, but undateable, so a
        // re-run of an old period priced it at today's table and a bracket change needed a deploy.
        return $this->tax->annualTax($t, $asOf);
    }

    /** Pay the year's 13th month. */
    /**
     * What a 13th-month run WOULD pay, without writing anything.
     *
     * @return array{rows: array<int, array<string, mixed>>, gross: float, tax: float, count: int}
     */
    public function thirteenthMonthPreview(int $year): array
    {
        $cap = (float) (PayrollSetting::current()->other_benefits_annual_cap ?? 0);
        $rows = $this->computeThirteenthMonth($year, $cap);

        return [
            'rows' => array_values($rows),
            'gross' => round(array_sum(array_column($rows, 'thirteenth_month')), 2),
            'tax' => round(array_sum(array_column($rows, 'tax')), 2),
            'count' => count($rows),
        ];
    }

    /** The ONE definition of what a year's 13th-month pay is measured from. */
    private function thirteenthMonthBasisFor(int $year): \Illuminate\Database\Query\Builder
    {
        return DB::table('hr_payslips')
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->whereNull('hr_payslips.deleted_at')
            ->whereNull('hr_payroll_runs.deleted_at')
            ->where('hr_payroll_runs.type', 'regular')
            ->where('hr_payroll_runs.status', 'completed')
            ->whereYear('hr_payroll_runs.cycle_start', $year);
    }

    /**
     * The arithmetic behind both the preview and the run, so they cannot disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    private function computeThirteenthMonth(int $year, float $cap): array
    {
        // 13th month is paid at year end, so it is taxed on the table in force then.
        $taxAsOf = "{$year}-12-31";

        // What the year actually paid, per employee, from the REGULAR runs only — including a
        // 13th-month run's own figures would compound it on a re-run.
        $ytd = $this->thirteenthMonthBasisFor($year)
            ->join('hr_employees', 'hr_payslips.employee_id', '=', 'hr_employees.id')
            ->leftJoin('users', 'hr_employees.user_id', '=', 'users.id')
            ->groupBy('hr_payslips.employee_id', 'users.name')
            ->select('hr_payslips.employee_id', 'users.name')
            ->selectRaw('SUM(hr_payslips.base_salary) AS basic')
            ->selectRaw('SUM(hr_payslips.other_benefits) AS other_benefits')
            ->selectRaw('SUM(hr_payslips.taxable_pay) AS taxable_pay')
            ->get();

        $rows = [];

        foreach ($ytd as $row) {
            // PH law: total BASIC salary earned in the year ÷ 12. Allowances and premium pay are
            // not part of it.
            $thirteenth = round(((float) $row->basic) / 12, 2);

            if ($thirteenth <= 0) {
                continue;
            }

            $otherBenefits = round((float) $row->other_benefits, 2);
            $excess = max(0.0, $thirteenth + $otherBenefits - $cap);
            $alreadyTaxed = max(0.0, (float) $row->taxable_pay);

            $tax = $excess > 0
                ? round(
                    $this->annualWithholdingTax($alreadyTaxed + $excess, $taxAsOf)
                        - $this->annualWithholdingTax($alreadyTaxed, $taxAsOf),
                    2,
                )
                : 0.0;

            $rows[] = [
                'employee_id' => (int) $row->employee_id,
                'name' => $row->name,
                'thirteenth_month' => $thirteenth,
                'other_benefits' => $otherBenefits,
                'taxable_excess' => round($excess, 2),
                'tax' => $tax,
                'net' => round($thirteenth - $tax, 2),
            ];
        }

        return $rows;
    }

    public function runThirteenthMonth(int $year, User $runner): PayrollRun
    {
        return DB::transaction(function () use ($year, $runner) {
            // The ₱90,000 other-benefits cap as it stood at that year end.
            $setting = PayrollSetting::asOf("{$year}-12-31");
            $cap = (float) ($setting->other_benefits_annual_cap ?? 0);

            // December, but on its own frequency so the unique (start, end, frequency) index can
            // never collide with an ordinary December run.
            $cycleStart = "{$year}-12-01";
            $cycleEnd = "{$year}-12-31";

            $run = PayrollRun::where('cycle_start', $cycleStart)
                ->where('cycle_end', $cycleEnd)
                ->where('frequency', 'thirteenth_month')
                ->lockForUpdate()
                ->first();

            if ($run && $run->isLocked()) {
                throw ValidationException::withMessages([
                    'year' => ['The ' . $year . ' 13th-month run is locked and can no longer be recomputed.'],
                ]);
            }

            $run ??= new PayrollRun;
            $run->fill([
                'cycle_start' => $cycleStart,
                'cycle_end' => $cycleEnd,
                'frequency' => 'thirteenth_month',
                'type' => 'thirteenth_month',
                'status' => 'completed',
                // A 13th-month run is paid on a date too, and its payslip says so.
                'scheduled_pay_date' => Carbon::parse($cycleEnd)
                    ->addDays((int) ($setting->pay_delay_days ?? 0))
                    ->toDateString(),
                'run_at' => now(),
                'run_by' => $runner->id,
                'policy_snapshot' => $this->policySnapshot($setting, $cycleEnd),
            ]);
            $run->save();

            $gross = 0.0;
            $deductions = 0.0;
            $count = 0;

            // This loop used to be a bare updateOrCreate, missing BOTH guards that
            // run() has. Re-running after part of the 13th month had been paid rewrote amounts
            // that were already declared disbursed (observed: a paid ₱27,500 silently became
            // ₱30,000), and an employee who no longer qualified kept a stale payslip that still
            // counted toward YTD, the remittance report and this run's own totals.
            $priorSlips = Payslip::where('payroll_run_id', $run->id)->get()->keyBy('employee_id');
            $paidEmployeeIds = [];

            foreach ($this->computeThirteenthMonth($year, $cap) as $row) {
                $paidEmployeeIds[] = $row['employee_id'];

                // Already paid out: its amounts are history. Its own totals still roll into the
                // run summary, so the run continues to reconcile to the sum of its payslips.
                $existing = $priorSlips->get($row['employee_id']);
                if ($existing && $existing->status === 'disbursed') {
                    $gross += (float) $existing->thirteenth_month;
                    $deductions += (float) $existing->withholding_tax;
                    $count++;

                    continue;
                }

                Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $row['employee_id']],
                    [
                        'base_salary' => 0, 'period_base' => 0, 'absence_deduction' => 0,
                        'reimbursements' => 0, 'allowances' => 0, 'allowances_breakdown' => [],
                        'holiday_premium' => 0, 'holiday_lines' => null, 'overtime_pay' => 0, 'night_diff_pay' => 0,
                        'rest_day_premium' => 0,
                        'thirteenth_month' => $row['thirteenth_month'],
                        // The bucket this slip was measured against, kept so the payslip can
                        // explain a withholding figure that has nothing to do with its own gross.
                        'other_benefits' => $row['other_benefits'],
                        'taxable_pay' => $row['taxable_excess'],
                        'gross' => $row['thirteenth_month'],
                        'sss' => 0, 'philhealth' => 0, 'pagibig' => 0,
                        'employer_sss' => 0, 'employer_philhealth' => 0, 'employer_pagibig' => 0,
                        'withholding_tax' => $row['tax'],
                        'other_deductions' => 0, 'other_deductions_breakdown' => [],
                        'total_deductions' => $row['tax'],
                        'deduction_carryover' => 0,
                        'deduction_carryover_breakdown' => null,
                        'net_pay' => $row['net'],
                        'status' => 'computed',
                    ],
                );

                $gross += $row['thirteenth_month'];
                $deductions += $row['tax'];
                $count++;
            }

            // Drop the computed slips of anyone who fell out of the set on this re-run
            // (their year was voided, they were separated, their basic went to zero). A disbursed
            // slip is never touched: that money left the company and is not ours to erase.
            $run->payslips()
                ->whereNotIn('employee_id', $paidEmployeeIds)
                ->where('status', 'computed')
                ->forceDelete();

            $run->update([
                'total_gross' => round($gross, 2),
                'total_deductions' => round($deductions, 2),
                'total_net' => round($gross - $deductions, 2),
                'employee_count' => $count,
            ]);

            $this->audit->log(
                'ran', 'Payroll',
                "13th month pay for {$year} — {$count} employee(s), ₱" . number_format($gross, 2)
                    . ' gross, ₱' . number_format($deductions, 2) . ' withheld',
                $run,
            );

            return $run->fresh();
        });
    }

    /**
     * Pay an off-cycle correction.
     *
     * @param  array<int, array{employee_id: int, amount: float|int|string, note?: string|null}>  $lines
     */
    public function runAdjustment(
        string $cycleStart,
        string $cycleEnd,
        string $reason,
        array $lines,
        User $runner,
    ): PayrollRun {
        return DB::transaction(function () use ($cycleStart, $cycleEnd, $reason, $lines, $runner) {
            $run = PayrollRun::where('cycle_start', $cycleStart)
                ->where('cycle_end', $cycleEnd)
                ->where('frequency', 'adjustment')
                ->lockForUpdate()
                ->first();

            if ($run && $run->isLocked()) {
                throw ValidationException::withMessages([
                    'cycle_start' => ['This adjustment run is locked and can no longer be changed.'],
                ]);
            }

            $setting = PayrollSetting::asOf($cycleEnd);

            $run ??= new PayrollRun;
            $run->fill([
                'cycle_start' => $cycleStart,
                'cycle_end' => $cycleEnd,
                // Its own frequency, so the period unique index can never collide with a regular
                // run over the same dates — the same device runThirteenthMonth() uses.
                'frequency' => 'adjustment',
                'type' => 'adjustment',
                'reason' => $reason,
                'status' => 'completed',
                'run_at' => now(),
                'run_by' => $runner->id,
                'scheduled_pay_date' => Carbon::parse($cycleEnd)
                    ->addDays((int) ($setting->pay_delay_days ?? 0))
                    ->toDateString(),
                'policy_snapshot' => $this->policySnapshot($setting, $cycleEnd),
            ]);
            $run->save();

            $priorSlips = Payslip::where('payroll_run_id', $run->id)->get()->keyBy('employee_id');
            $year = (int) Carbon::parse($cycleEnd)->year;
            $taxAsOf = $cycleEnd;

            $gross = 0.0;
            $deductions = 0.0;
            $count = 0;
            $paidEmployeeIds = [];

            foreach ($lines as $line) {
                $employeeId = (int) $line['employee_id'];
                $amount = round((float) $line['amount'], 2);

                if ($amount <= 0) {
                    continue;
                }

                $paidEmployeeIds[] = $employeeId;

                // Never rewrite money already paid out.
                $existing = $priorSlips->get($employeeId);
                if ($existing && $existing->status === 'disbursed') {
                    $gross += (float) $existing->gross;
                    $deductions += (float) $existing->total_deductions;
                    $count++;

                    continue;
                }

                // Marginal on the year already taxed, from the regular runs — the same basis the
                // 13th-month calculation uses, so the two agree.
                $tax = $this->marginalTaxOn($employeeId, $year, $taxAsOf, $amount);

                Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employeeId],
                    [
                        'base_salary' => 0, 'period_base' => 0, 'absence_deduction' => 0,
                        'reimbursements' => 0, 'allowances' => 0, 'allowances_breakdown' => [],
                        'holiday_premium' => 0, 'holiday_lines' => null, 'overtime_pay' => 0, 'night_diff_pay' => 0,
                        'rest_day_premium' => 0, 'thirteenth_month' => 0, 'other_benefits' => 0,
                        'adjustment_pay' => $amount,
                        'adjustment_note' => $line['note'] ?? null,
                        // Recorded so a later correction in the same year taxes on top of it.
                        'taxable_pay' => $amount,
                        'gross' => $amount,
                        'sss' => 0, 'philhealth' => 0, 'pagibig' => 0,
                        'employer_sss' => 0, 'employer_philhealth' => 0, 'employer_pagibig' => 0,
                        'withholding_tax' => $tax,
                        'other_deductions' => 0, 'other_deductions_breakdown' => [],
                        'total_deductions' => $tax,
                        'deduction_carryover' => 0,
                        'deduction_carryover_breakdown' => null,
                        'net_pay' => round($amount - $tax, 2),
                        'status' => 'computed',
                    ],
                );

                $gross += $amount;
                $deductions += $tax;
                $count++;
            }

            // Lines removed from the batch on a recompute leave no orphan behind.
            $run->payslips()
                ->whereNotIn('employee_id', $paidEmployeeIds)
                ->where('status', 'computed')
                ->forceDelete();

            $run->update([
                'total_gross' => round($gross, 2),
                'total_deductions' => round($deductions, 2),
                'total_net' => round($gross - $deductions, 2),
                'employee_count' => $count,
            ]);

            $this->audit->log(
                'ran', 'Payroll',
                "Off-cycle adjustment for {$cycleStart} – {$cycleEnd} — {$count} employee(s), ₱"
                    . number_format($gross, 2) . " gross — {$reason}",
                $run,
            );

            return $run->fresh();
        });
    }

    /**
     * Correct an already-computed period by adding a line to each named employee's OWN payslip.
     *
     * @param  array<int, array{employee_id: int, amount: float|int|string, note?: string|null}>  $lines
     * @param  'pay'|'pay_reversal'|'deduction'  $kind
     */
    public function amendRun(
        PayrollRun $run,
        string $reason,
        array $lines,
        ?string $issuedOn,
        User $actor,
        string $kind = 'pay',
    ): PayrollRun {
        if ($run->isLocked()) {
            throw ValidationException::withMessages([
                'payroll_run_id' => ['This payroll run is locked. Unlock it before amending its payslips.'],
            ]);
        }

        // A `processing` run is mid-write: its payslips have no settled figures to correct, and the
        // recompute in flight would overwrite the amendment as it went.
        if ($run->status !== 'completed') {
            throw ValidationException::withMessages([
                'payroll_run_id' => ["The {$run->status} payroll run must finish before it can be amended."],
            ]);
        }

        $issuedOn ??= now('Asia/Manila')->toDateString();
        $kind = in_array($kind, ['pay', 'pay_reversal', 'deduction'], true) ? $kind : 'pay';

        return DB::transaction(function () use ($run, $reason, $lines, $issuedOn, $actor, $kind) {
            $slips = Payslip::where('payroll_run_id', $run->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('employee_id');

            // AN EMPLOYEE WITH NO PAYSLIP IN THIS PERIOD IS REFUSED, not silently skipped. There is
            // nothing to amend, and quietly dropping the line would report the correction as paid
            // while the money stayed where it was — the exact failure the corrections queue exists
            // to prevent. Naming them says what to do instead: run the period for them.
            $missing = collect($lines)
                ->map(fn (array $line) => (int) $line['employee_id'])
                ->unique()
                ->reject(fn (int $id) => $slips->has($id));

            if ($missing->isNotEmpty()) {
                $names = Employee::withTrashed()
                    ->with('user')
                    ->whereIn('id', $missing->all())
                    ->get()
                    ->map(fn (Employee $e) => $e->user?->name ?? $e->employee_no)
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'lines' => ["This period has no payslip for {$names}, so there is nothing to amend. Run the period for them first."],
                ]);
            }

            // The tax year and table are the CORRECTED period's, not today's. A March correction
            // raised in August is still March's money, and 's table is dated.
            $year = (int) $run->cycle_end->year;
            $taxAsOf = $run->cycle_end->toDateString();

            $applied = 0;
            $total = 0.0;
            $employeeIds = [];
            // The sign lives here and nowhere else: every line arrives as a magnitude, so this is
            // the single place a correction becomes money in or money out.
            $sign = $kind === 'pay' ? 1 : -1;

            foreach ($lines as $line) {
                $employeeId = (int) $line['employee_id'];
                // A magnitude even if a client sent a sign — the kind decides the direction, and a
                // "-500" typed into a deduction batch must not quietly become a payment.
                $magnitude = round(abs((float) $line['amount']), 2);

                if ($magnitude <= 0) {
                    continue;
                }

                $slip = $slips->get($employeeId);
                $signed = round($sign * $magnitude, 2);

                // A missed deduction did not change what was earned, so no tax moves with it. The
                // other two kinds are compensation, taxed marginally on the year (negative for a
                // reversal, which refunds what was withheld on pay that is being taken back).
                $tax = $kind === 'deduction'
                    ? 0.0
                    : $this->marginalTaxOn($employeeId, $year, $taxAsOf, $signed);

                // REVERSING MORE PAY THAN THE PAYSLIP HOLDS is a data error, not a correction: the
                // pay being unwound is not on this period. Refused rather than clamped, because
                // clamping would silently reverse less than HR asked for. A deduction is different
                // — it is an obligation, and what pay cannot cover carries to the next period.
                if ($kind === 'pay_reversal' && $slip->disbursed_amount === null) {
                    $effect = round($magnitude - abs($tax), 2);

                    if ($effect > round((float) $slip->net_pay, 2) + 0.005) {
                        $employee = Employee::withTrashed()->with('user')->find($employeeId);
                        $name = $employee?->user?->name ?? $employee?->employee_no ?? "employee #{$employeeId}";

                        throw ValidationException::withMessages([
                            'lines' => [
                                "Reversing ₱" . number_format($magnitude, 2) . " would take more than {$name}'s payslip pays"
                                    . ' (₱' . number_format((float) $slip->net_pay, 2) . ' net). Reverse at most that,'
                                    . ' or correct the period the pay was actually on.',
                            ],
                        ]);
                    }
                }

                PayslipAmendment::create([
                    'payslip_id' => $slip->id,
                    'kind' => $kind,
                    'amount' => $signed,
                    'tax' => $tax,
                    'reason' => $reason,
                    'note' => $line['note'] ?? null,
                    'issued_on' => $issuedOn,
                    'created_by' => $actor->id,
                ]);

                $this->applyAmendmentTo($slip, $kind, $signed, $tax, $line['note'] ?? null, $reason);

                $employeeIds[] = $employeeId;
                $total = round($total + $magnitude, 2);
                $applied++;
            }

            $this->refreshRunTotals($run);

            // The other half of amending a settled period — see flagTaxStaleRuns(). A deduction is
            // exempt: it moves nothing a later run's annualisation reads, because taxable pay and
            // the tax withheld are exactly what it leaves alone.
            $period = $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y');

            if ($kind !== 'deduction') {
                $this->flagTaxStaleRuns($run, $employeeIds, "The {$period} period was amended after this run was computed.");
            }

            $direction = match ($kind) {
                'pay_reversal' => 'reversed',
                'deduction' => 'deducted',
                default => 'added',
            };

            $this->audit->log(
                'updated', 'Payroll',
                "Amended the {$period} payroll — {$applied} payslip(s), ₱" . number_format($total, 2)
                    . " {$direction} — {$reason}",
                $run,
            );

            return $run->fresh()->load(['payslips.employee.user', 'payslips.employee.department', 'runner', 'locker']);
        });
    }

    /** Withholding on an amount paid ON TOP of what an employee has already earned this year. */
    private function marginalTaxOn(int $employeeId, int $year, string $asOf, float $amount): float
    {
        $alreadyTaxed = (float) $this->thirteenthMonthBasisFor($year)
            ->where('hr_payslips.employee_id', $employeeId)
            ->sum('hr_payslips.taxable_pay');

        // A NEGATIVE AMOUNT REFUNDS, and the arithmetic is the same subtraction read the other way:
        // the tax on a year without this pay, less the tax on the year with it. The floor matters
        // only for a reversal larger than everything taxed this year — an income below zero is not
        // a tax table's input, and the refund is capped at the whole year's tax either way.
        return round(
            $this->annualWithholdingTax(max(0.0, $alreadyTaxed + $amount), $asOf)
                - $this->annualWithholdingTax(max(0.0, $alreadyTaxed), $asOf),
            2,
        );
    }

    /** Fold one correction into a payslip's stored figures. */
    private function applyAmendmentTo(Payslip $slip, string $kind, float $amount, float $tax, ?string $note, string $reason): void
    {
        if ($kind === 'deduction') {
            $this->applyAmendmentDeductionTo($slip, abs($amount), $note ?: $reason);

            return;
        }

        $slip->update([
            'adjustment_pay' => round((float) $slip->adjustment_pay + $amount, 2),
            // The most recent line's note. The full list lives in the ledger; this keeps the single
            // stored column meaningful for everything that already reads it.
            'adjustment_note' => $note ?? $slip->adjustment_note,
            'taxable_pay' => round((float) $slip->taxable_pay + $amount, 2),
            'gross' => round((float) $slip->gross + $amount, 2),
            'withholding_tax' => round((float) $slip->withholding_tax + $tax, 2),
            'total_deductions' => round((float) $slip->total_deductions + $tax, 2),
            // Floored, as every other net in this service is: a reversal on an already-paid payslip
            // can exceed what the period computed, and a payslip claiming a negative entitlement is
            // not a figure any document can print. What was overpaid is the balance, not the net.
            'net_pay' => round(max(0, (float) $slip->net_pay + $amount - $tax), 2),
        ]);
    }

    /** Fold a MISSED DEDUCTION into a payslip — a benefit share, a uniform, an unrecorded obligation. */
    private function applyAmendmentDeductionTo(Payslip $slip, float $amount, string $label): void
    {
        $available = max(0.0, round((float) $slip->net_pay, 2));
        $taken = round(min($amount, $available), 2);
        $short = round($amount - $taken, 2);

        $carryover = $slip->deduction_carryover_breakdown ?? [];
        if ($short > 0) {
            $carryover['other_deductions'] = round((float) ($carryover['other_deductions'] ?? 0) + $short, 2);
        }

        $slip->update([
            'other_deductions' => round((float) $slip->other_deductions + $taken, 2),
            // Appended so the breakdown keeps footing to the total and the employee can
            // read what was taken off them rather than one larger unexplained figure.
            'other_deductions_breakdown' => [
                ...($slip->other_deductions_breakdown ?? []),
                ['name' => 'Correction — ' . Str::limit($label, 60), 'amount' => $taken],
            ],
            'total_deductions' => round((float) $slip->total_deductions + $taken, 2),
            'net_pay' => round(max(0, (float) $slip->net_pay - $taken), 2),
            'deduction_carryover' => round((float) $slip->deduction_carryover + $short, 2),
            'deduction_carryover_breakdown' => $carryover ?: null,
        ]);
    }

    /**
     * The corrections standing against a set of payslips, totalled per EMPLOYEE.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Payslip>  $slips  keyed by employee_id
     * @return array<int, array{amount: float, tax: float, deduction_lines: list<array{name: string, amount: float}>, note: string|null}>
     */
    private function amendmentTotalsFor(Collection $slips): array
    {
        if ($slips->isEmpty()) {
            return [];
        }

        $byPayslip = PayslipAmendment::whereIn('payslip_id', $slips->pluck('id')->all())
            // ADDED LINES ONLY. An override changes a line the recompute has just produced for
            // itself, and is re-applied by `withLineOverrides()` against that fresh figure. Summing
            // it in here as well would add the correction to a line that already carries it.
            ->whereNull('target_key')
            ->orderBy('issued_on')
            ->orderBy('id')
            ->get()
            ->groupBy('payslip_id');

        $totals = [];

        foreach ($slips as $employeeId => $slip) {
            $rows = $byPayslip->get($slip->id);

            if ($rows === null || $rows->isEmpty()) {
                continue;
            }

            [$deductions, $earnings] = $rows->partition(fn (PayslipAmendment $a) => $a->isDeduction());

            $totals[(int) $employeeId] = [
                'amount' => round((float) $earnings->sum(fn (PayslipAmendment $a) => (float) $a->amount), 2),
                'tax' => round((float) $earnings->sum(fn (PayslipAmendment $a) => (float) $a->tax), 2),
                // One line per deduction, in the order they were raised, so a recompute rebuilds the
                // same named breakdown lines the amendment itself wrote.
                'deduction_lines' => $deductions
                    ->map(fn (PayslipAmendment $a) => [
                        'name' => 'Correction — ' . Str::limit((string) ($a->note ?: $a->reason), 60),
                        'amount' => abs(round((float) $a->amount, 2)),
                    ])
                    ->values()
                    ->all(),
                'note' => $earnings->last()?->note ?? $rows->last()->note,
            ];
        }

        return $totals;
    }

    /**
     * Re-apply a payslip's corrections to a freshly computed set of figures.
     *
     * @param  array<string, mixed>  $slip
     * @param  array{amount: float, tax: float, deduction_lines: list<array{name: string, amount: float}>, note: string|null}  $totals
     * @return array<string, mixed>
     */
    private function withAmendments(array $slip, array $totals): array
    {
        $slip['adjustment_pay'] = $totals['amount'];
        $slip['adjustment_note'] = $totals['note'];
        $slip['taxable_pay'] = round((float) $slip['taxable_pay'] + $totals['amount'], 2);
        $slip['gross'] = round((float) $slip['gross'] + $totals['amount'], 2);
        $slip['withholding_tax'] = round((float) $slip['withholding_tax'] + $totals['tax'], 2);
        $slip['total_deductions'] = round((float) $slip['total_deductions'] + $totals['tax'], 2);
        // Recomputed from the two figures above rather than nudged, so it stays the same arithmetic
        // `computePayslip()` ends on.
        $slip['net_pay'] = round(max(0, (float) $slip['gross'] - (float) $slip['total_deductions']), 2);

        foreach ($totals['deduction_lines'] as $line) {
            $room = max(0.0, round((float) $slip['net_pay'], 2));
            $taken = round(min((float) $line['amount'], $room), 2);
            $short = round((float) $line['amount'] - $taken, 2);

            if ($taken > 0) {
                $slip['other_deductions'] = round((float) $slip['other_deductions'] + $taken, 2);
                $slip['other_deductions_breakdown'] = [
                    ...(array) ($slip['other_deductions_breakdown'] ?? []),
                    ['name' => $line['name'], 'amount' => $taken],
                ];
                $slip['total_deductions'] = round((float) $slip['total_deductions'] + $taken, 2);
                $slip['net_pay'] = round(max(0, (float) $slip['net_pay'] - $taken), 2);
            }

            if ($short > 0) {
                $carryover = (array) ($slip['deduction_carryover_breakdown'] ?? []);
                $carryover['other_deductions'] = round((float) ($carryover['other_deductions'] ?? 0) + $short, 2);

                $slip['deduction_carryover'] = round((float) $slip['deduction_carryover'] + $short, 2);
                $slip['deduction_carryover_breakdown'] = $carryover;
            }
        }

        return $slip;
    }

    /** CORRECT A LINE THAT IS ALREADY ON A PAYSLIP — the engine said ₱500, HR says ₱300. */
    public function overrideLine(
        Payslip $payslip,
        string $key,
        ?float $value,
        ?string $label,
        string $reason,
        ?string $issuedOn,
        User $actor,
    ): Payslip {
        $run = $payslip->payrollRun;

        $this->assertCorrectable($run);

        if (! PayslipLines::exists($key)) {
            throw ValidationException::withMessages([
                'target_key' => ['That is not a line on a payslip.'],
            ]);
        }

        $issuedOn ??= now('Asia/Manila')->toDateString();

        return DB::transaction(function () use ($payslip, $run, $key, $value, $label, $reason, $issuedOn, $actor) {
            // Null when the figure and the wording are already what HR asked for — the payslip is
            // returned unchanged rather than gaining a correction that corrects nothing.
            $slip = $this->writeOverride($payslip, $run, $key, $value, $label, $reason, $issuedOn, $actor)
                ?? $payslip;

            $this->refreshRunTotals($run);

            return $slip->fresh()->load(['amendments.creator', 'employee.user', 'payrollRun']);
        });
    }

    /**
     * ONE LINE, MANY PAYSLIPS — the correction that is one decision rather than N identical ones.
     *
     * @param  list<int>|null  $employeeIds  null for everyone this run pays
     * @return array{run: PayrollRun, applied: int, skipped: list<string>}
     */
    public function overrideLineForRun(
        PayrollRun $run,
        string $key,
        ?float $value,
        ?string $label,
        string $reason,
        ?string $issuedOn,
        ?array $employeeIds,
        User $actor,
    ): array {
        $this->assertCorrectable($run);

        if (! PayslipLines::exists($key)) {
            throw ValidationException::withMessages([
                'target_key' => ['That is not a line on a payslip.'],
            ]);
        }

        $issuedOn ??= now('Asia/Manila')->toDateString();

        return DB::transaction(function () use ($run, $key, $value, $label, $reason, $issuedOn, $employeeIds, $actor) {
            $slips = $run->payslips()
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->with('employee.user')
                ->lockForUpdate()
                ->get();

            if ($slips->isEmpty()) {
                throw ValidationException::withMessages([
                    'employee_ids' => ['This period has no payslips for the employees named.'],
                ]);
            }

            $applied = 0;
            $skipped = [];

            foreach ($slips as $slip) {
                try {
                    if ($this->writeOverride($slip, $run, $key, $value, $label, $reason, $issuedOn, $actor) !== null) {
                        $applied++;
                    }
                } catch (ValidationException $e) {
                    $skipped[] = ($slip->employee?->user?->name ?? $slip->employee?->employee_no ?? "employee #{$slip->employee_id}")
                        . ' — ' . collect($e->errors())->flatten()->first();
                }
            }

            if ($applied === 0) {
                throw ValidationException::withMessages([
                    'target_key' => [
                        $skipped === []
                            ? 'Every payslip in this period already reads that way, so nothing was corrected.'
                            : 'None of the payslips in this period have that line, so nothing was corrected.',
                    ],
                ]);
            }

            $this->refreshRunTotals($run);

            $this->audit->log(
                'updated', 'Payroll',
                "Corrected " . (PayslipLines::labelFor($key) ?? $key) . " on {$applied} payslip(s) of the "
                    . $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y')
                    . ' period — ' . $reason,
                $run,
            );

            return [
                'run' => $run->fresh()->load(['payslips.employee.user', 'payslips.employee.department', 'runner', 'locker']),
                'applied' => $applied,
                'skipped' => $skipped,
            ];
        });
    }

    /** Write ONE correction to ONE payslip, inside a transaction the caller already owns. */
    private function writeOverride(
        Payslip $payslip,
        PayrollRun $run,
        string $key,
        ?float $value,
        ?string $label,
        string $reason,
        string $issuedOn,
        User $actor,
    ): ?Payslip {
        $slip = Payslip::whereKey($payslip->id)->lockForUpdate()->firstOrFail();

        // THE ENGINE'S OWN FIGURES, captured before anything is corrected. Everything below is
        // measured against them: what to print struck through, what "remove this edit" restores
        // to, and what a second edit to the same line is a change FROM.
        $snapshot = $this->engineSnapshotOf($slip);
        $runType = $run->type ?? 'regular';

        $engineValue = $this->lineValueIn($snapshot, $runType, $key);

        if ($engineValue === null) {
            throw ValidationException::withMessages([
                'target_key' => ['This payslip has no such line, so there is nothing to correct.'],
            ]);
        }

        $existing = PayslipAmendment::where('payslip_id', $slip->id)
            ->where('target_key', $key)
            ->first();

        // What the line reads as RIGHT NOW: HR's last correction if there is one, the engine's
        // figure otherwise. The change to apply is measured from here, while the figure printed
        // struck through stays the engine's — an edit is always a correction OF the computation,
        // never of the previous correction.
        $from = $existing?->override_amount !== null
            ? round((float) $existing->override_amount, 2)
            : $engineValue;

        $to = $value === null ? $from : round($value, 2);

        // A CORRECTION THAT CORRECTS NOTHING IS NOT RECORDED. It matters for the whole-period form
        // of this: waiving a late charge across a run reaches every payslip, and most of them never
        // had one. Writing a row for those would put "0.00" struck through beside "0.00" on a dozen
        // documents, and report fifteen payslips corrected when two were.
        if (abs($to - $from) < 0.005
            && ($label ?: null) === ($existing?->label_override ?: null)
            && $existing === null) {
            return null;
        }

        $figures = $this->applyLineChange(
            $slip->attributesToArray(),
            (int) $slip->employee_id,
            (int) $run->cycle_end->year,
            $run->cycle_end->toDateString(),
            $key,
            $from,
            $to,
            ! empty($slip->separation_pay_taxable),
        );

        $slip->update([...$figures, 'engine_snapshot' => $snapshot]);

        $override = PayslipAmendment::updateOrCreate(
            ['payslip_id' => $slip->id, 'target_key' => $key],
            [
                // An override is not one of the three appended kinds — it replaces a figure
                // rather than adding pay or taking it. `kind` stays at its default so every
                // existing reader of the ledger keeps working; `target_key` is what says which
                // of the two this row is.
                'kind' => 'pay',
                'override_amount' => $value === null ? $existing?->override_amount : $to,
                // Refreshed rather than frozen, so the struck-through figure is always what the
                // engine last computed — see the migration.
                'original_amount' => $engineValue,
                // The figure HR actually disagreed with, frozen here and never rewritten by a
                // run. It is what lets a later recompute report that the engine has since
                // changed its mind — see the migration.
                'edited_against' => $engineValue,
                'label_override' => $label,
                // The signed effect on the line, kept so the ledger can be totalled and audited
                // without re-deriving it. NOT a term in `adjustment_pay`: an override changes a
                // line that is already counted, and adding it again would count it twice.
                'amount' => round(($value === null ? $from : $to) - $engineValue, 2),
                'tax' => 0,
                'reason' => $reason,
                'issued_on' => $issuedOn,
                'created_by' => $actor->id,
            ],
        );

        $this->flagStaleAfterCorrection($run, (int) $slip->employee_id, $key);

        $this->audit->log(
            'updated', 'Payroll',
            $this->correctionAuditLine($run, $slip, $override, $engineValue),
            $run,
        );

        return $slip;
    }

    /** REMOVE A CORRECTION, putting the line back to what the engine computed. */
    public function removeLineOverride(Payslip $payslip, PayslipAmendment $override, User $actor): Payslip
    {
        $run = $payslip->payrollRun;

        $this->assertCorrectable($run);

        if ((int) $override->payslip_id !== (int) $payslip->id || $override->target_key === null) {
            throw ValidationException::withMessages([
                'override' => ['That correction does not belong to this payslip.'],
            ]);
        }

        return DB::transaction(function () use ($payslip, $run, $override, $actor) {
            $slip = Payslip::whereKey($payslip->id)->lockForUpdate()->firstOrFail();
            $key = (string) $override->target_key;

            $snapshot = $this->engineSnapshotOf($slip);
            $engineValue = $this->lineValueIn($snapshot, $run->type ?? 'regular', $key);

            if ($engineValue !== null && $override->override_amount !== null) {
                $figures = $this->applyLineChange(
                    $slip->attributesToArray(),
                    (int) $slip->employee_id,
                    (int) $run->cycle_end->year,
                    $run->cycle_end->toDateString(),
                    $key,
                    round((float) $override->override_amount, 2),
                    $engineValue,
                    ! empty($slip->separation_pay_taxable),
                );

                $slip->update($figures);
            }

            // Soft-deleted, like every other HR record: a correction that was reversed is history
            // too, and the question "who changed this payslip, and who changed it back" has to stay
            // answerable after the fact.
            $override->delete();

            $this->refreshRunTotals($run);
            $this->flagStaleAfterCorrection($run, (int) $slip->employee_id, $key);

            $this->audit->log(
                'updated', 'Payroll',
                'Removed the correction on ' . ($override->targetLabel() ?? $key)
                    . ' — ' . ($this->employeeName((int) $slip->employee_id))
                    . ', ' . $run->cycle_end->format('M Y') . ' payslip',
                $run,
            );

            return $slip->fresh()->load(['amendments.creator', 'employee.user', 'payrollRun']);
        });
    }

    /** A period can be corrected only while it is finished and still open. */
    private function assertCorrectable(?PayrollRun $run): void
    {
        if ($run === null) {
            throw ValidationException::withMessages([
                'payroll_run_id' => ['This payslip does not belong to a payroll run.'],
            ]);
        }

        if ($run->isLocked()) {
            throw ValidationException::withMessages([
                'payroll_run_id' => ['This payroll run is locked. Unlock it before correcting its payslips.'],
            ]);
        }

        if ($run->status !== 'completed') {
            throw ValidationException::withMessages([
                'payroll_run_id' => ["The {$run->status} payroll run must finish before it can be corrected."],
            ]);
        }
    }

    /**
     * The engine's figures for a payslip, captured once and kept.
     *
     * @return array<string, mixed>
     */
    private function engineSnapshotOf(Payslip $payslip): array
    {
        $snapshot = $payslip->engine_snapshot;

        return is_array($snapshot) && $snapshot !== []
            ? $snapshot
            : $payslip->attributesToArray();
    }

    /**
     * What a named line reads as, on a given set of figures.
     *
     * @param  array<string, mixed>  $slip
     */
    private function lineValueIn(array $slip, string $runType, string $key): ?float
    {
        foreach (PayslipDocument::deductionRows($slip) as $row) {
            if ($row['key'] === $key) {
                return round((float) $row['amount'], 2);
            }
        }

        foreach (PayslipDocument::earningLines($slip, $runType, []) as $line) {
            if ($line['key'] === $key) {
                return round((float) $line['amount'], 2);
            }
        }

        // THE GOVERNMENT LINES ARE NOT IN EITHER LIST. The document prints SSS, PhilHealth,
        // Pag-IBIG and the withholding tax straight from their columns, in a table of their own —
        // so they are read from the columns here too. Missing this is not a display bug: the
        // correction is applied as a change FROM whatever this returns, and a statutory line read
        // as ₱0 would add HR's figure to the one already there instead of replacing it.
        $class = PayslipLines::classFor($key);

        if ($class === PayslipLines::STATUTORY || $class === PayslipLines::TAX) {
            return round((float) ($slip[PayslipLines::columnFor($key)] ?? 0), 2);
        }

        // A dynamic key names a line the PAYSLIP named — a benefit share, a loan, an allowance. If
        // it is not in the breakdown it is not on this payslip, and there is nothing to correct.
        return PayslipLines::isDynamic($key) ? null : 0.0;
    }

    /**
     * Move one line from `$from` to `$to` and let every total follow.
     *
     * @param  array<string, mixed>  $slip
     * @return array<string, mixed>
     */
    private function applyLineChange(
        array $slip,
        int $employeeId,
        int $year,
        string $asOf,
        string $key,
        float $from,
        float $to,
        bool $separationTaxable = false,
    ): array {
        $delta = round($to - $from, 2);

        if ($delta == 0.0) {
            return $slip;
        }

        $class = PayslipLines::classFor($key, $separationTaxable);
        $column = PayslipLines::columnFor($key);

        $add = function (string $field, float $by) use (&$slip): void {
            $slip[$field] = round((float) ($slip[$field] ?? 0) + $by, 2);
        };

        switch ($class) {
            case PayslipLines::TAXABLE_EARNING:
            case PayslipLines::UNTAXED_EARNING:
                if ($column !== null) {
                    $add($column, $delta);
                }

                // The document prints the FULL period basic and charges absences against it, so a
                // corrected basic has to move both halves or `period_base − absence_deduction`
                // would stop equalling the pay that was actually made.
                if ($key === 'basic') {
                    $add('base_salary', $delta);
                }

                if (str_starts_with($key, PayslipLines::ALLOWANCE_PREFIX)) {
                    $slip = $this->setNamedLine($slip, 'allowances_breakdown', $key, PayslipLines::ALLOWANCE_PREFIX, $to);
                    $add('allowances', $delta);
                }

                $add('gross', $delta);

                if ($class === PayslipLines::TAXABLE_EARNING) {
                    $add('taxable_pay', $delta);
                    $add('withholding_tax', $this->marginalTaxOn($employeeId, $year, $asOf, $delta));
                }
                break;

            case PayslipLines::ATTENDANCE_CHARGE:
                $add('absence_deduction', $delta);
                $add('base_salary', -$delta);
                $add('gross', -$delta);
                $add('taxable_pay', -$delta);

                if ($column !== null && $column !== 'absence_deduction') {
                    $add($column, $delta);
                }

                $add('withholding_tax', $this->marginalTaxOn($employeeId, $year, $asOf, -$delta));
                break;

            case PayslipLines::OTHER_DEDUCTION:
                $slip = $this->setNamedLine($slip, 'other_deductions_breakdown', $key, PayslipLines::OTHER_PREFIX, $to);
                $add('other_deductions', $delta);
                break;

            case PayslipLines::STATUTORY:
            case PayslipLines::TAX:
                if ($column !== null) {
                    $add($column, $delta);
                }
                break;

            default:
                throw ValidationException::withMessages([
                    'target_key' => ['That line cannot be corrected.'],
                ]);
        }

        return $this->settleTotals($slip);
    }

    /**
     * Set the amount on a line the payslip named itself, matched by the slug in its key.
     *
     * @param  array<string, mixed>  $slip
     * @return array<string, mixed>
     */
    private function setNamedLine(array $slip, string $field, string $key, string $prefix, float $to): array
    {
        $lines = (array) ($slip[$field] ?? []);
        $slug = substr($key, strlen($prefix));
        $found = false;

        foreach ($lines as $i => $line) {
            if (PayslipDocument::slug((string) $line['name']) === $slug) {
                $lines[$i]['amount'] = round($to, 2);
                $found = true;
                break;
            }
        }

        if (! $found) {
            throw ValidationException::withMessages([
                'target_key' => ['This payslip no longer has that line, so there is nothing to correct.'],
            ]);
        }

        $slip[$field] = array_values($lines);

        return $slip;
    }

    /**
     * Recompute the totals from the lines, which is the only way they are ever set.
     *
     * @param  array<string, mixed>  $slip
     * @return array<string, mixed>
     */
    private function settleTotals(array $slip): array
    {
        $f = fn (string $field) => round((float) ($slip[$field] ?? 0), 2);

        $gross = $f('gross');
        $total = round(
            $f('sss') + $f('philhealth') + $f('pagibig') + $f('withholding_tax') + $f('other_deductions'),
            2,
        );

        // The shortfall the engine already recorded is not counted again: those components store
        // what was COLLECTED, and the carryover beside them is what a later period will recover.
        if ($total > $gross + 0.005) {
            throw ValidationException::withMessages([
                'amount' => [
                    'This correction leaves ₱' . number_format($total, 2) . ' of deductions against ₱'
                        . number_format($gross, 2) . ' of pay. The payslip cannot cover it — lower another'
                        . ' line, or add the difference as a deduction so it carries to the next period.',
                ],
            ]);
        }

        $slip['total_deductions'] = $total;
        $slip['net_pay'] = round(max(0, $gross - $total), 2);

        return $slip;
    }

    /** Mark the later runs a correction to this period has just invalidated. */
    private function flagStaleAfterCorrection(PayrollRun $run, int $employeeId, string $key): void
    {
        // Separation pay is read as TAXABLE here whatever this payslip says, which is the
        // conservative direction: the cost of marking a run stale that did not need it is a
        // recompute, and the cost of not marking one that did is a year settled against figures
        // that no longer exist.
        $class = PayslipLines::classFor($key, true);

        if (! in_array($class, [PayslipLines::TAXABLE_EARNING, PayslipLines::ATTENDANCE_CHARGE, PayslipLines::TAX], true)) {
            return;
        }

        $period = $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y');

        $this->flagTaxStaleRuns($run, [$employeeId], "The {$period} period was corrected after this run was computed.");
    }

    /** What the audit trail records for one corrected line — old figure, new figure, and why. */
    private function correctionAuditLine(PayrollRun $run, Payslip $slip, PayslipAmendment $override, float $engineValue): string
    {
        $line = $override->targetLabel() ?? (string) $override->target_key;
        $to = $override->override_amount === null ? null : (float) $override->override_amount;

        return 'Corrected ' . $line . ' on ' . $this->employeeName((int) $slip->employee_id)
            . "'s " . $run->cycle_end->format('M Y') . ' payslip — '
            . ($to === null
                ? 'renamed only'
                : '₱' . number_format($engineValue, 2) . ' → ₱' . number_format($to, 2))
            . ' — ' . $override->reason;
    }

    /** An employee's name for a message, falling back to their number and then to their id. */
    private function employeeName(int $employeeId): string
    {
        $employee = Employee::withTrashed()->with('user')->find($employeeId);

        return $employee?->user?->name ?? $employee?->employee_no ?? "employee #{$employeeId}";
    }

    /**
     * The corrections standing against lines the engine produced, per EMPLOYEE, oldest first.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Payslip>  $slips  keyed by employee_id
     * @return array<int, \Illuminate\Support\Collection<int, PayslipAmendment>>
     */
    private function lineOverridesFor(Collection $slips): array
    {
        if ($slips->isEmpty()) {
            return [];
        }

        $byPayslip = PayslipAmendment::whereIn('payslip_id', $slips->pluck('id')->all())
            ->whereNotNull('target_key')
            ->orderBy('id')
            ->get()
            ->groupBy('payslip_id');

        $out = [];

        foreach ($slips as $employeeId => $slip) {
            $rows = $byPayslip->get($slip->id);

            if ($rows !== null && $rows->isNotEmpty()) {
                $out[(int) $employeeId] = $rows;
            }
        }

        return $out;
    }

    /**
     * Re-apply a payslip's line corrections to a freshly computed set of figures.
     *
     * @param  array<string, mixed>  $slip
     * @param  \Illuminate\Support\Collection<int, PayslipAmendment>  $overrides
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function withLineOverrides(array $slip, Employee $employee, PayrollRun $run, $overrides): array
    {
        $snapshot = $slip['engine_snapshot'];
        $runType = $run->type ?? 'regular';
        $year = (int) $run->cycle_end->year;
        $asOf = $run->cycle_end->toDateString();
        $name = $employee->user?->name ?? $employee->employee_no;
        $notes = [];

        foreach ($overrides as $override) {
            $key = (string) $override->target_key;
            $label = $override->targetLabel() ?? $key;

            // MEASURED AGAINST THE SNAPSHOT, not against the figures being mutated. Several
            // attendance charges write to the same column, so reading each one's "before" off the
            // running total would make the second correction a change from the first.
            $engineValue = $this->lineValueIn($snapshot, $runType, $key);

            if ($engineValue === null) {
                $notes[] = "{$name}: this period no longer has a “{$label}” line, so the correction on it was not applied.";

                continue;
            }

            if ($override->edited_against !== null
                && round((float) $override->edited_against, 2) !== $engineValue) {
                $notes[] = "{$name}: “{$label}” now computes to ₱" . number_format($engineValue, 2)
                    . ', not the ₱' . number_format((float) $override->edited_against, 2)
                    . ' it was corrected from — the correction still stands.';
            }

            // The struck-through figure follows the engine, so an amended payslip never prints an
            // original the engine has stopped producing.
            $override->update(['original_amount' => $engineValue]);

            if ($override->override_amount === null) {
                // A label-only correction moves no money — it is applied where the document is
                // built, from the wording on the ledger row.
                continue;
            }

            try {
                $slip = $this->applyLineChange(
                    $slip,
                    (int) $employee->id,
                    $year,
                    $asOf,
                    $key,
                    $engineValue,
                    round((float) $override->override_amount, 2),
                    ! empty($slip['separation_pay_taxable']),
                );
            } catch (ValidationException $e) {
                // A period can come back thinner than the one the correction was entered against —
                // an absence found after the fact. Refusing the whole run over one correction would
                // hold up everybody's pay, so this one is left unapplied and named.
                $notes[] = "{$name}: the correction on “{$label}” no longer fits this period and was not applied — "
                    . collect($e->errors())->flatten()->first();
            }
        }

        return [$slip, $notes];
    }

    /**
     * Bring a run's headline totals back in line with the payslips it holds.
     *
     * Summed from the rows rather than accumulated, so the run reconciles to its payslips by
     * construction — the same reason `registerTotals()` derives deductions from gross and net.
     */
    private function refreshRunTotals(PayrollRun $run): void
    {
        $slips = $run->payslips()->get();

        $run->update([
            'total_gross' => round((float) $slips->sum(fn (Payslip $s) => (float) $s->gross), 2),
            'total_deductions' => round((float) $slips->sum(fn (Payslip $s) => (float) $s->total_deductions), 2),
            'total_net' => round((float) $slips->sum(fn (Payslip $s) => (float) $s->net_pay), 2),
            'employee_count' => $slips->count(),
        ]);
    }

    /**
     * Mark the later runs whose withholding was settled against a year-to-date that just moved.
     *
     * @param  list<int>  $employeeIds
     */
    private function flagTaxStaleRuns(PayrollRun $amended, array $employeeIds, string $reason): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        $stale = PayrollRun::query()
            ->whereKeyNot($amended->id)
            ->where('status', 'completed')
            ->whereNull('tax_stale_at')
            // Same tax year, and after the period that changed. An earlier run cannot have been
            // computed from a later one.
            ->whereYear('cycle_end', (int) $amended->cycle_end->year)
            ->where('cycle_end', '>=', $amended->cycle_end->toDateString())
            ->whereHas('payslips', fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->where(function ($q) use ($employeeIds) {
                // The three run types whose tax is computed on the year, whatever their dates…
                $q->whereIn('type', ['thirteenth_month', 'adjustment', 'final_pay'])
                    // …and any run that settled the tax year, which is what makes an ordinary
                    // December cutoff depend on every period before it.
                    ->orWhereHas('payslips', fn ($p) => $p
                        ->whereIn('employee_id', $employeeIds)
                        ->where('tax_annualisation', '!=', 0));
            })
            ->get();

        foreach ($stale as $run) {
            $run->update(['tax_stale_at' => now(), 'tax_stale_reason' => $reason]);

            $this->audit->log(
                'updated', 'Payroll',
                'The ' . $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y')
                    . ' run needs recomputing: its withholding was settled on year-to-date figures that have since changed',
                $run,
            );
        }

        return $stale->count();
    }

    /**
     * Pay a leaver everything terminal they are owed.
     *
     * @param  array{separation_pay?: float|null, separation_pay_taxable?: bool, unused_leave_days?: float|null, reason: string}  $data
     */
    public function runFinalPay(Employee $employee, array $data, User $runner): PayrollRun
    {
        if ($employee->separation_date === null) {
            throw ValidationException::withMessages([
                'employee' => ['This employee has no separation date, so there is no final pay to compute. Record their last working day first.'],
            ]);
        }

        $separationDate = $employee->separation_date->toDateString();

        return DB::transaction(function () use ($employee, $data, $runner, $separationDate) {
            $setting = PayrollSetting::asOf($separationDate);
            $year = (int) Carbon::parse($separationDate)->year;

            // One final-pay run per employee per separation date. Its own frequency keeps it clear of
            // the (cycle_start, cycle_end, frequency) unique index, the same device the 13th-month
            // and adjustment runs use.
            $run = PayrollRun::where('cycle_start', $separationDate)
                ->where('cycle_end', $separationDate)
                ->where('frequency', 'final_pay:' . $employee->id)
                ->lockForUpdate()
                ->first();

            if ($run && $run->isLocked()) {
                throw ValidationException::withMessages([
                    'employee' => ['This final pay is locked and can no longer be recomputed.'],
                ]);
            }

            $existing = $run
                ? Payslip::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->first()
                : null;

            if ($existing && $existing->status === 'disbursed') {
                throw ValidationException::withMessages([
                    'employee' => ['This final pay has already been paid out. Reverse the payslip before recomputing it.'],
                ]);
            }

            $run ??= new PayrollRun;
            $run->fill([
                'cycle_start' => $separationDate,
                'cycle_end' => $separationDate,
                'frequency' => 'final_pay:' . $employee->id,
                'type' => 'final_pay',
                'reason' => $data['reason'],
                'status' => 'completed',
                'run_at' => now(),
                'run_by' => $runner->id,
                'scheduled_pay_date' => Carbon::parse($separationDate)
                    ->addDays((int) ($setting->pay_delay_days ?? 0))
                    ->toDateString(),
                'policy_snapshot' => $this->policySnapshot($setting, $separationDate),
            ]);
            $run->save();

            // --- 1. unused leave, converted at the daily rate ---------------------------------
            $unusedDays = round((float) ($data['unused_leave_days'] ?? $this->unusedLeaveDaysFor($employee, $year)), 2);

            $policy = $this->attendancePolicy->asOf($separationDate);
            $frequency = $employee->pay_frequency ?: $setting->frequency;
            $periodsPerYear = PayScheduleService::periodsPerYear($frequency);
            $period = $this->schedule->currentPeriodFor($frequency, $setting->anchor_date);
            $workingDays = max(1, $this->attendance->scheduledWorkdaysFor($employee, $period['start'], $period['end']));

            $dailyRate = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays);
            $leaveConversion = round($unusedDays * $dailyRate, 2);

            // --- 2. separation pay, as entered ------------------------------------------------
            $separationPay = round((float) ($data['separation_pay'] ?? 0), 2);
            $separationTaxable = (bool) ($data['separation_pay_taxable'] ?? false);

            // --- 3. what is taxable of the above ----------------------------------------------
            // Up to 12 days of monetised VL is exempt (RR 29-2025). The excess joins the ₱90,000
            // other-benefits bucket rather than becoming taxable compensation directly.
            $exemptLeaveDays = min($unusedDays, self::EXEMPT_LEAVE_CONVERSION_DAYS);
            $excessLeave = round(max(0, $unusedDays - $exemptLeaveDays) * $dailyRate, 2);

            $otherBenefits = $excessLeave;
            $cap = (float) ($setting->other_benefits_annual_cap ?? 0);

            // What the year already put into the bucket, so the cap is shared rather than reset.
            $bucketSoFar = (float) $this->thirteenthMonthBasisFor($year)
                ->where('hr_payslips.employee_id', $employee->id)
                ->sum('hr_payslips.other_benefits');

            $taxableFromBucket = round(max(0, ($bucketSoFar + $otherBenefits) - $cap) - max(0, $bucketSoFar - $cap), 2);
            $taxableTerminal = round($taxableFromBucket + ($separationTaxable ? $separationPay : 0), 2);

            // --- 4. annualise the year actually worked ---------------------------------------
            $priorTaxable = (float) $this->thirteenthMonthBasisFor($year)
                ->where('hr_payslips.employee_id', $employee->id)
                ->sum('hr_payslips.taxable_pay');
            $priorWithheld = (float) $this->thirteenthMonthBasisFor($year)
                ->where('hr_payslips.employee_id', $employee->id)
                ->sum('hr_payslips.withholding_tax');

            $annualDue = $this->annualWithholdingTax($priorTaxable + $taxableTerminal, $separationDate);
            $tax = round($annualDue - $priorWithheld, 2);

            $gross = round($leaveConversion + $separationPay, 2);

            // --- 5. settle outstanding loans against what is left ----------------------------
            // The tax is SIGNED and must stay signed through both lines below. An earlier draft
            // wrapped it in max(0, …) — which silently discarded the refund a mid-year leaver is
            // owed, leaving net pay at zero on a payslip that should have paid them back several
            // thousand pesos. Caught by the refund test, which is why that test asserts on net_pay
            // rather than only on withholding_tax.
            $availableNet = round(max(0, $gross - $tax), 2);
            $loanSettlement = $this->loans->settleOnSeparation($employee, $run, $availableNet);

            $totalDeductions = round($tax + $loanSettlement, 2);

            $payslip = Payslip::updateOrCreate(
                ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                [
                    'base_salary' => 0, 'period_base' => 0, 'absence_deduction' => 0,
                    'reimbursements' => 0, 'allowances' => 0, 'allowances_breakdown' => [],
                    'holiday_premium' => 0, 'holiday_lines' => null, 'overtime_pay' => 0, 'night_diff_pay' => 0,
                    'rest_day_premium' => 0, 'thirteenth_month' => 0, 'adjustment_pay' => 0,
                    'separation_pay' => $separationPay,
                    'separation_pay_taxable' => $separationTaxable,
                    'leave_conversion' => $leaveConversion,
                    'leave_conversion_days' => $unusedDays,
                    'loan_settlement' => $loanSettlement,
                    'other_benefits' => $otherBenefits,
                    'taxable_pay' => $taxableTerminal,
                    'gross' => $gross,
                    'sss' => 0, 'philhealth' => 0, 'pagibig' => 0,
                    'employer_sss' => 0, 'employer_philhealth' => 0, 'employer_pagibig' => 0,
                    // Signed: a mid-year leaver is usually owed a refund, and clamping it at zero
                    // would keep tax they never owed.
                    'withholding_tax' => $tax,
                    'tax_annualisation' => $tax,
                    'other_deductions' => $loanSettlement,
                    'other_deductions_breakdown' => $loanSettlement > 0
                        ? [['name' => 'Outstanding loan balance', 'amount' => $loanSettlement]]
                        : [],
                    'total_deductions' => $totalDeductions,
                    'deduction_carryover' => 0,
                    'net_pay' => round($gross - $totalDeductions, 2),
                    'status' => 'computed',
                ],
            );

            $run->update([
                'total_gross' => $gross,
                'total_deductions' => $totalDeductions,
                'total_net' => (float) $payslip->net_pay,
                'employee_count' => 1,
            ]);

            $employee->loadMissing('user');
            $this->audit->log(
                'ran', 'Payroll',
                'Final pay for ' . ($employee->user?->name ?? $employee->name)
                    . ' — ₱' . number_format($gross, 2) . ' gross ('
                    . $unusedDays . ' leave day(s) converted, ₱' . number_format($separationPay, 2)
                    . ' separation pay), ₱' . number_format($loanSettlement, 2) . ' loan settled'
                    . ' — ' . $data['reason'],
                $run,
            );

            return $run->fresh();
        });
    }

    /** Monetised unused vacation leave is exempt up to this many days. */
    private const EXEMPT_LEAVE_CONVERSION_DAYS = 12;

    /**
     * Unused leave days this employee has left, in whichever mode the company runs.
     *
     * Only PAID types count: converting unpaid leave to cash would pay for time that was never
     * going to be paid. Shared mode holds a single pool, per-type mode holds a row per type.
     */
    /**
     * What one employee's unused paid leave would cost if it were paid out today.
     *
     * @return array{days: float, daily_rate: float, amount: float}
     */
    public function leaveLiabilityFor(Employee $employee, ?int $year = null, ?string $asOf = null): array
    {
        $tz = config('app.timezone_local');
        $on = $asOf ?? now($tz)->toDateString();
        $year ??= (int) Carbon::parse($on, $tz)->year;

        $days = round($this->unusedLeaveDaysFor($employee, $year), 2);

        $setting = PayrollSetting::asOf($on);
        $policy = $this->attendancePolicy->asOf($on);
        $frequency = $employee->pay_frequency ?: $setting->frequency;
        $periodsPerYear = PayScheduleService::periodsPerYear($frequency);
        $period = $this->schedule->currentPeriodFor($frequency, $setting->anchor_date);
        $workingDays = max(1, $this->attendance->scheduledWorkdaysFor($employee, $period['start'], $period['end']));

        $dailyRate = $this->dailyRateFor($employee, $policy, $periodsPerYear, $workingDays);

        return [
            'days' => $days,
            'daily_rate' => round($dailyRate, 2),
            // Negative balances can't create a negative liability — an over-taken balance is an
            // employee owing the company time, which is not an accrual and not this report's business.
            'amount' => round(max(0, $days) * $dailyRate, 2),
        ];
    }

    private function unusedLeaveDaysFor(Employee $employee, int $year): float
    {
        if (LeaveSetting::current()->allotment_mode === LeaveSetting::MODE_SHARED) {
            $pool = SharedLeaveBalance::where('employee_id', $employee->id)->where('year', $year)->first();

            return $pool ? max(0, (float) $pool->entitled_days - (float) $pool->used_days) : 0.0;
        }

        return (float) $employee->leaveBalances()
            ->where('year', $year)
            ->whereHas('leaveType', fn ($q) => $q->where('is_paid', true))
            ->get()
            ->sum(fn ($b) => max(0, (float) $b->entitled_days - (float) $b->used_days));
    }

    public function listRuns(): Collection
    {
        return PayrollRun::withCount('payslips')
            // The register states gross BEFORE the absence charge (see Payslip::registerTotals()),
            // which the stored run columns do not hold. Summed here so a page of runs still costs
            // one query rather than one per row.
            ->withCount(['payslips as slips_count'])
            ->withSum('payslips as slips_gross', 'gross')
            ->withSum('payslips as slips_absence', 'absence_deduction')
            ->withSum('payslips as slips_net', 'net_pay')
            ->latest('cycle_start')
            ->get();
    }

    public function runWithBreakdown(PayrollRun $run): PayrollRun
    {
        return $run->load(['payslips.employee.user', 'payslips.employee.department', 'runner', 'locker']);
    }

    /**
     * Seal a finished cycle. Only when every payslip in it has actually been paid
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

        // AND NOT A PENNY STILL OWED. `disbursed` stopped meaning "paid in full" once a paid payslip
        // could be amended: the correction raises the net while the transferred amount stays put. The
        // status test above would happily seal a period — permanently, that being what locking is —
        // over a balance nobody had paid yet.
        $owing = $run->payslips()
            ->whereNotNull('disbursed_amount')
            ->whereColumn('net_pay', '>', 'disbursed_amount')
            ->count();

        if ($owing > 0) {
            throw ValidationException::withMessages([
                'locked_at' => [
                    "{$owing} payslip(s) were amended after being paid and still have a balance outstanding. Pay it before locking the run.",
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

    /** HR marks a payslip as disbursed (paid out manually) — the terminal state. */
    public function markDone(Payslip $payslip, ?User $actor = null, ?string $idempotencyKey = null): Payslip
    {
        // The one act that asserts money left the company, now with preconditions.
        if ($payslip->status === 'disbursed') {
            if ($idempotencyKey !== null && $payslip->disbursement_key === $idempotencyKey) {
                return $payslip->load(['employee.user', 'employee.department']);
            }

            // AMENDED AFTER IT WAS PAID — so this is not a duplicate, it is the rest of the money.
            // The payslip's net grew past what was actually transferred, and the balance has to be
            // payable through the same act that paid the original: refusing here would leave the
            // correction visible on the document and permanently unpayable, which is the dead end
            // the amendment mechanism exists to remove.
            if ($payslip->isPartiallyPaid()) {
                return $this->payOutstanding($payslip, $actor, $idempotencyKey);
            }

            throw ValidationException::withMessages([
                'status' => ['This payslip has already been marked paid. Reverse it first if that was wrong.'],
            ]);
        }

        // Any other state is not disbursable: `processing` means the run is mid-write, and a
        // payslip that does not exist yet as `computed` has no settled amount to pay.
        if ($payslip->status !== 'computed') {
            throw ValidationException::withMessages([
                'status' => ["A payslip must be computed before it can be marked paid; this one is {$payslip->status}."],
            ]);
        }

        // The run must have finished. Disbursing out of a `processing` run pays an amount the run
        // is still in the middle of deciding — and re-running would then skip that payslip as
        // already-disbursed (run():1480), freezing a half-computed figure permanently.
        $run = $payslip->payrollRun;
        if ($run && $run->status !== 'completed') {
            throw ValidationException::withMessages([
                'status' => ["The {$run->status} payroll run must finish before its payslips can be marked paid."],
            ]);
        }

        return DB::transaction(function () use ($payslip, $actor, $idempotencyKey) {
            $payslip->update([
                'status' => 'disbursed',
                // Provenance for the one operation an auditor asks about first. `updated_at` was
                // the only record of "when", and any later write overwrote it.
                'disbursed_at' => now(),
                'disbursed_by' => $actor?->id ?? Auth::id(),
                'disbursement_key' => $idempotencyKey,
                // What left the company, frozen here. Everything downstream reads the balance as
                // `net_pay − disbursed_amount`, so a payslip amended after this point states the
                // difference instead of claiming it was already transferred.
                'disbursed_amount' => $payslip->net_pay,
            ]);

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

    /** Pay the balance an amendment left on an already-disbursed payslip. */
    private function payOutstanding(Payslip $payslip, ?User $actor, ?string $idempotencyKey): Payslip
    {
        $balance = $payslip->amountOutstanding();

        return DB::transaction(function () use ($payslip, $actor, $idempotencyKey, $balance) {
            $payslip->update([
                'disbursed_at' => now(),
                'disbursed_by' => $actor?->id ?? Auth::id(),
                'disbursement_key' => $idempotencyKey,
                'disbursed_amount' => $payslip->net_pay,
            ]);

            $payslip->load(['employee.user', 'employee.department']);
            $name = $payslip->employee->user?->name ?? $payslip->employee->name;

            $this->audit->log(
                'updated', 'Payroll',
                "Paid the ₱" . number_format($balance, 2) . " outstanding on {$name}'s "
                    . $payslip->payrollRun?->cycle_start?->format('M j') . '–'
                    . $payslip->payrollRun?->cycle_end?->format('M j, Y') . ' payslip after an amendment',
                $payslip,
            );

            return $payslip;
        });
    }

    /**
     * Undo the above — the payslip goes back to `computed` and the claims it settled go
     * back to `scheduled`, i.e. owed on this run again. Use when a disbursement was
     * recorded in error.
     */
    public function reopenPayslip(Payslip $payslip, ?string $reason = null): Payslip
    {
        if ($payslip->status !== 'disbursed') {
            throw ValidationException::withMessages([
                'status' => ['This payslip has not been disbursed — there is nothing to undo.'],
            ]);
        }

        $run = $payslip->payrollRun;
        if ($run?->isLocked()) {
            throw ValidationException::withMessages([
                'status' => ['The payroll run is locked. Unlock it before reversing a payslip.'],
            ]);
        }

        return DB::transaction(function () use ($payslip, $reason) {
            $payslip->update([
                'status' => 'computed',
                // Clear the provenance. Leaving `disbursement_key` set would make the
                // idempotency check treat the NEXT genuine disbursement from the same client as a
                // duplicate and silently no-op it, and a stale `disbursed_by` would credit someone
                // with a payment that was reversed.
                'disbursed_at' => null,
                'disbursed_by' => null,
                'disbursement_key' => null,
                // Nothing has been transferred against it any more, so it owes its whole net again.
                // Left set, a reversed-then-amended payslip would report a balance of only the
                // amendment while claiming the original had been paid.
                'disbursed_amount' => null,
            ]);

            // Put back only what this payslip paid; a claim HR settled by hand outside
            // payroll has no run link and must stay paid.
            $claims = Reimbursement::where('employee_id', $payslip->employee_id)
                ->where('payroll_run_id', $payslip->payroll_run_id)
                ->where('status', 'paid')
                ->get();

            foreach ($claims as $claim) {
                $claim->update(['status' => 'scheduled']);
            }

            // (a) — and put back the loan instalments this payslip collected. Without
            // this, un-paying a payslip wrote off debt against money the company did not pay,
            // and a loan the payslip had settled stayed settled. Same invariant as the claims
            // above; loans simply never had it.
            $reversedLoans = $this->loans->reverseForPayslip($payslip);

            $payslip->load(['employee.user', 'employee.department']);
            $name = $payslip->employee->user?->name ?? $payslip->employee->name;
            $this->audit->log(
                'reopened',
                'Payroll',
                "Reversed {$name}'s disbursed payslip — ₱" . number_format((float) $payslip->net_pay, 2)
                    . ($claims->isNotEmpty() ? ', ' . $claims->count() . ' reimbursement(s) back to scheduled' : '')
                    . ($reversedLoans > 0 ? ", {$reversedLoans} loan payment(s) reversed" : '')
                    . ($reason ? " — {$reason}" : ''),
                $payslip,
            );

            return $payslip;
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
    public function run(
        string $cycleStart,
        string $cycleEnd,
        string $frequency,
        User $runner,
        ?string $reason = null,
    ): PayrollRun {
        $startedAt = microtime(true);

        // Refuse before anything is written if an active statutory contribution has no
        // rate version in force for this cycle. Outside the transaction on purpose: this is a
        // precondition on the request, not a step in the run, and HR should see the reason rather
        // than a rolled-back run that appears to have done nothing.
        $this->contributions->assertRatesInForce($cycleEnd);

        $run = DB::transaction(function () use ($cycleStart, $cycleEnd, $frequency, $runner, $reason) {
            // The settings IN FORCE for this period, not whatever they are today.
            // `frequency` drives periodsPerYear (how annual pay is sliced and how the withholding
            // base is annualised) and the timing settings decide which cutoff carries the monthly
            // deductions, so reading them live let a re-run of an old cycle silently reprice it.
            $setting = PayrollSetting::asOf($cycleEnd);
            // Frequency + period length drive per-period pay; month position + timing
            // settings decide which cutoff statutory deductions and tax land on.
            $periodsPerYear = PayScheduleService::periodsPerYear($frequency);
            [$monthIndex, $monthCount] = $this->schedule->monthPosition($frequency, $setting->anchor_date, $cycleStart, $cycleEnd);
            $dist = [
                'index' => $monthIndex,
                'count' => $monthCount,
                'deduction_timing' => $setting->deduction_timing,
                'tax_timing' => $setting->tax_timing,
                // And which cutoff the monthly allowance itself lands on.
                'allowance_timing' => $setting->allowance_timing,
                // The ceiling above which allowances become taxable (null = none).
                'other_benefits_annual_cap' => $setting->other_benefits_annual_cap,
                // Resolves the statutory rates in force for this period.
                'cycle_end' => $cycleEnd,
                // Decides whether a dated plan enrolment covers this period at all.
                'cycle_start' => $cycleStart,
                // So a period can record its own share of the year's taxable pay.
                'periods_per_year' => $periodsPerYear,
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

            // A locked cycle is closed for good — refuse before anything is touched.
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
                        // Stated HERE as well as in the column default, deliberately,
                        // for the reason PayrollSetting::current() documents: `create()` returns a
                        // model built from the attributes it INSERTED, so a DB-side default lands
                        // on the row while the in-memory instance still reads null. The overlap
                        // guard below filters on `$run->type`, and a null there would match no run
                        // at all — silently disabling the double-pay guard on every first run of a
                        // cycle, which is worse than the bug it was added to fix.
                        'type' => 'regular',
                        'status' => 'processing',
                        // Stamped once, here. Derived from the delay in force NOW, then
                        // frozen: changing pay_delay_days later must not move the date on a
                        // payslip an employee is already holding.
                        'scheduled_pay_date' => Carbon::parse($cycleEnd)
                            ->addDays((int) ($setting->pay_delay_days ?? 0))
                            ->toDateString(),
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
            // And the corrections already added to them, so recomputing the period from attendance
            // does not silently erase money HR has granted — see amendmentTotalsFor().
            $amendments = $this->amendmentTotalsFor($priorSlips);
            // And the corrections HR made to lines the engine had ALREADY produced, which are
            // re-applied further down against whatever this recompute produces for them — see
            // withLineOverrides(). Kept apart from the totals above because the two are opposites:
            // one adds money the recompute knows nothing about, the other replaces a figure the
            // recompute has just worked out for itself.
            $lineOverrides = $this->lineOverridesFor($priorSlips);
            // What a re-run has to say about them afterwards: overrides whose line the engine no
            // longer produces, and overrides the engine has since changed its mind about.
            $overrideNotes = [];
            $paidElsewhere = [];
            // Employees whose pay was already disbursed and is therefore left untouched.
            $skippedDisbursed = [];

            $totalGross = $totalDed = $totalNet = 0;
            $count = 0;
            // Reimbursements auto-paid by this run — tallied for one audit line.
            $reimbPaidCount = 0;
            $reimbPaidTotal = 0.0;

            // Only the employees in this pay group (their pay_frequency, or the default), and
            // only those employed at some point during the cycle.
            $employees = $this->payGroupEmployees($frequency, $cycleStart, $cycleEnd, $setting->frequency);

            // Holidays in this cycle (date => type), fetched once for the whole run.
            $holidayMap = $this->holidays->typedHolidaysBetween(Carbon::parse($cycleStart), Carbon::parse($cycleEnd));

            // The attendance policy drives the daily-rate basis — fetch once, reuse.
            $policy = $this->attendancePolicy->asOf($cycleEnd);

            // The salary each employee was actually on during this period.
            $salaryAsOf = $this->salariesAsOf($employees, $cycleEnd);

            // What earlier periods failed to collect from each employee, so this period
            // can recover it from whatever room is left after its own obligations. Excludes this
            // run so a re-run does not settle against its own previous attempt.
            $arrears = $this->deductionArrearsFor($employees, $run->id);

            // Is this the cycle that closes the tax year?
            $taxYear = Carbon::parse($cycleStart)->year;
            $yearEnd = $taxYear . '-12-31';
            $isFinalCycleOfYear = $cycleStart <= $yearEnd && $cycleEnd >= $yearEnd;

            // What each employee has already been taxed on, and already had withheld, this year —
            // from the REGULAR completed runs only, which is the same basis 13th month uses. One
            // query for the whole run rather than two per employee.
            $yearToDateTax = [];
            if ($isFinalCycleOfYear) {
                // Same year the boundary above was resolved against — a straddling cutoff must
                // settle the year it CLOSES, not the one it happens to end in.
                $yearToDateTax = $this->thirteenthMonthBasisFor($taxYear)
                    ->whereIn('hr_payslips.employee_id', $employees->pluck('id'))
                    // This run's own prior compute must not count as "already withheld", or a
                    // re-run would settle the year against itself and drift on every attempt.
                    ->where('hr_payslips.payroll_run_id', '!=', $run->id)
                    ->groupBy('hr_payslips.employee_id')
                    ->selectRaw('hr_payslips.employee_id, SUM(hr_payslips.taxable_pay) AS taxable_prior, SUM(hr_payslips.withholding_tax) AS withheld_prior')
                    ->get()
                    ->keyBy('employee_id')
                    ->map(fn ($r) => [
                        'taxable_prior' => (float) $r->taxable_prior,
                        'withheld_prior' => (float) $r->withheld_prior,
                    ])
                    ->all();
            }

            // What the month's EARLIER cutoffs already earned and already paid in
            // statutory contributions. One query for the run, keyed by employee, in the shape and
            // for the reason `$yearToDateTax` above uses: a query per employee here would be an
            // N+1 by construction.
            $monthToDate = $this->monthToDateContributions($run, $cycleStart, $cycleEnd, $employees->pluck('id'));

            // Who already has a payslip in an OVERLAPPING run, resolved in ONE query
            // instead of an exists() per employee. Same question, same answer, 1/N the round trips.
            $paidInAnotherRun = Payslip::whereIn('employee_id', $employees->pluck('id'))
                ->where('payroll_run_id', '!=', $run->id)
                ->whereHas('payrollRun', fn ($q) => $q
                    ->where('type', $run->type)
                    ->where('cycle_start', '<=', $cycleEnd)
                    ->where('cycle_end', '>=', $cycleStart))
                ->pluck('employee_id')
                ->unique()
                ->flip();

            foreach ($employees as $employee) {
                // Price this period at the salary that was in force THEN, not the
                // one on the record today. Set on the in-memory model (never saved) so every
                // pricing path — daily rate, holiday premium, the overtime hourly rate, the
                // rest-day premium — picks it up consistently, instead of threading the figure
                // through six signatures and risking one of them being missed.
                if (isset($salaryAsOf[$employee->id])) {
                    $employee->base_salary = $salaryAsOf[$employee->id];
                }

                // Already paid out: never recompute a disbursed payslip. Its status
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

                // Reimbursements folded into this period's pay, PLUS any already scheduled by
                // THIS run (so a re-run keeps them folded). Auto-flipped to `scheduled` + linked
                // to the run below.
                $reimbursements = Reimbursement::where('employee_id', $employee->id)
                    ->whereDate('expense_date', '<=', $cycleEnd)
                    ->where(fn ($q) => $q
                        ->where(fn ($unclaimed) => $unclaimed
                            ->whereIn('status', ['approved', 'scheduled'])
                            ->whereNull('payroll_run_id'))
                        ->orWhere('payroll_run_id', $run->id))
                    ->get();
                $reimbTotal = (float) $reimbursements->sum('amount');

                // Attendance is the source of truth, per the employee's OWN schedule:
                // scheduled workdays drive the daily rate; unpaid days (absences +
                // missing records on past workdays, minus approved leave) prorate pay.
                $workingDays = $this->attendance->scheduledWorkdaysFor($employee, $cycleStart, $cycleEnd);
                // Management staff are on a FIXED salary — unpaidDaysDetailFor() returns 0 for
                // them, so pay is never prorated by attendance. holidayPremiumFor() likewise
                // pays them nothing UNLESS `management_holiday_premium` is on in Company → Rules.
                // $policy is passed EXPLICITLY. It always accepted one (the preview/dry-run
                // override, step 6b) and this call never supplied it, so the late and undertime
                // deductions re-read the live singleton instead of the policy resolved above.
                // That was invisible while the two were always the same object; now that the
                // policy is dated, omitting it would price every deduction at today's rules and
                // silently defeat asOf() for the one part of the payslip it matters most to.
                $unpaidDetail = $this->attendance->unpaidDaysDetailFor($employee, $cycleStart, $cycleEnd, $policy);

                // Premium for working on a (non-working) holiday in this cycle, plus the days
                // behind it for the payslip to print.
                $holiday = $this->holidayPremiumFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy);
                $holidayPremium = $holiday['pay'];
                // Rest days worked feed BOTH the rest-day premium and the overtime cap below.
                $restByDate = $this->attendance->restDayWorkedMinutesFor($employee, $cycleStart, $cycleEnd, $holidayMap);
                $restDay = $this->restDayPremiumFor($employee, $periodsPerYear, $workingDays, $restByDate, $policy);
                // One overtime source, shared by the OT line and the night differential.
                $otByDate = $this->overtimeMinutesByDate($employee, $cycleStart, $cycleEnd, $policy, $restByDate);
                $overtime = $this->overtimePayFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy, $otByDate);
                $nightDiff = $this->nightDiffPayFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy, $otByDate);

                // On the cycle that closes the tax year, hand this employee's prior
                // totals down so computePayslip settles the year instead of projecting it again.
                // Absent on every other cycle, which leaves the existing behaviour untouched.
                $employeeDist = $isFinalCycleOfYear
                    ? [...$dist, 'year_end_true_up' => $yearToDateTax[$employee->id] ?? ['taxable_prior' => 0.0, 'withheld_prior' => 0.0]]
                    : $dist;

                // This employee's outstanding shortfall, if any. Absent for almost
                // everyone, which keeps the ordinary payslip untouched.
                if (isset($arrears[$employee->id])) {
                    $employeeDist['arrears'] = $arrears[$employee->id];
                }

                // The month so far, so a contribution on `earnings` can be charged on
                // the WHOLE month rather than on this cutoff doubled.
                $employeeDist['month_to_date'] = $monthToDate[$employee->id]
                    ?? ['earnings' => 0.0, 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0];

                $slip = $this->payslipFor($employee, $reimbTotal, $unpaidDetail->unpaidDays, $periodsPerYear, $workingDays, $employeeDist, $holidayPremium, $unpaidDetail, $policy, $overtime['pay'], $overtime['minutes'], $nightDiff['pay'], $nightDiff['minutes'], $restDay['pay'], $restDay['minutes'], $holiday['lines']);

                // A correction added to this period belongs to the period, not to the run that
                // happened to compute it. `payslipFor()` prices the cutoff from attendance and knows
                // nothing about amendments, so re-applying the ledger here is what keeps a re-run
                // from quietly reversing a correction — and the figures below (loans, the run totals)
                // then see the amended net, as they must.
                if (isset($amendments[$employee->id])) {
                    $slip = $this->withAmendments($slip, $amendments[$employee->id]);
                }

                $payslip = Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                    [...$slip, 'status' => $priorStatus[$employee->id] ?? 'computed'],
                );

                // loans/cash advances come LAST, because what they can collect
                // depends on what the payslip can still afford. They're post-tax and don't
                // touch gross, so folding them in afterwards is exact rather than a re-compute.
                // Always called (even at zero) so a cancelled loan's stale ledger row is cleared.
                $loans = $this->loans->collectForPeriod(
                    $employee, $run, $cycleEnd, (float) $slip['net_pay'], $payslip,
                    // The diminishing-balance rate is per year; this splits it per payday.
                    $periodsPerYear,
                );

                if ($loans['total'] > 0) {
                    $slip['other_deductions'] = round((float) $slip['other_deductions'] + $loans['total'], 2);
                    $slip['other_deductions_breakdown'] = [...$slip['other_deductions_breakdown'], ...$loans['lines']];
                    $slip['total_deductions'] = round((float) $slip['total_deductions'] + $loans['total'], 2);
                    $slip['net_pay'] = max(0, round((float) $slip['net_pay'] - $loans['total'], 2));
                    // Stored on its own, so the payslip can show the cost of borrowing separately
                    // from the repayment — they are different things to the person paying them.
                    $slip['loan_interest'] = round((float) ($slip['loan_interest'] ?? 0) + $loans['interest'], 2);

                    $payslip->update([
                        'other_deductions' => $slip['other_deductions'],
                        'other_deductions_breakdown' => $slip['other_deductions_breakdown'],
                        'total_deductions' => $slip['total_deductions'],
                        'net_pay' => $slip['net_pay'],
                        'loan_interest' => $slip['loan_interest'],
                    ]);
                }

                // THE ENGINE'S OWN FIGURES FOR THIS PERIOD — everything the engine does, and nothing
                // a person has said about it. Recorded on the payslip because a correction to a
                // line is stored as the value HR wants, not as a nudge: without the figure it
                // replaced, "put this line back" would have nothing to restore to and a second
                // edit would have nothing to measure the engine's original against.
                $slip['engine_snapshot'] = Arr::except($slip, ['engine_snapshot']);

                // AND NOW THE CORRECTIONS TO THOSE LINES, replayed on top. This is what keeps an
                // edit from disappearing the next time the period is run: the figure is re-applied
                // against whatever the engine has just computed, not against what it computed when
                // HR made the edit.
                if (isset($lineOverrides[$employee->id])) {
                    [$slip, $notes] = $this->withLineOverrides($slip, $employee, $run, $lineOverrides[$employee->id]);
                    $overrideNotes = [...$overrideNotes, ...$notes];
                }

                // One write for the snapshot and for whatever the corrections moved. Idempotent on
                // the payslips that have neither: every field here is the one already stored.
                $payslip->update($slip);

                // auto-flip the folded claims to paid + link them to this run.
                // Idempotent — on a re-run, claims already paid by this run are untouched.
                // Computing is not paying. A claim folded into a payslip becomes
                // `scheduled` ("included in the Jul 16–31 payslip"), NOT `paid`; it only becomes
                // paid when HR marks that payslip disbursed (markDone). Before this, running
                // payroll flipped claims to `paid` before a peso had left the company, and a
                // later re-run that dropped the employee left them stranded as paid against a
                // run with no payslip.
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
            $keep = $employees->pluck('id')->diff($paidElsewhere);
            // And never one a PAYMENT still points at. See the method for why
            // `status` alone stopped being a safe test for "droppable".
            $this->assertDroppable($run, $keep, $frequency);
            $run->payslips()
                ->whereNotIn('employee_id', $keep)
                ->where('status', 'computed')
                ->forceDelete();

            // Same cleanup for the loan ledger: an employee dropped from this run
            // must not keep a repayment against it, or their balance would stay wrongly reduced.
            LoanPayment::where('payroll_run_id', $run->id)
                ->whereHas('loan', fn ($q) => $q->whereNotIn('employee_id', $keep))
                ->delete();

            // And release reimbursements scheduled onto this run for anyone no longer
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
                // Step 4b / — every rule that priced this run, so an old payslip can
                // always be explained. A record, not a replay: see policySnapshot().
                'policy_snapshot' => $this->policySnapshot($setting, $cycleEnd),
                // Whatever made this run's withholding stale has just been recomputed against the
                // current year-to-date, which is the only thing that resolves the mark.
                'tax_stale_at' => null,
                'tax_stale_reason' => null,
            ]);

            // Audit trail: who ran (or re-ran) which pay cycle, for how many people.
            $freqLabel = ucwords(str_replace('_', ' ', $frequency));
            $this->audit->log(
                $isRerun ? 'updated' : 'created',
                'Payroll',
                ($isRerun ? 'Re-ran' : 'Ran') . " {$freqLabel} payroll for "
                    . "{$cycleStart} – {$cycleEnd} — {$count} employee(s), net ₱"
                    . number_format(round($totalNet, 2), 2)
                    // Recomputing a settled period changes figures an employee may already be
                    // holding, so the WHY belongs on the same line as the what.
                    . ($reason ? " — {$reason}" : ''),
                $run,
            );

            // AND WHAT THE RECOMPUTE FOUND UNDERNEATH THE CORRECTIONS. An override is absolute and
            // still wins, so nothing here changed a figure — but a correction sitting on a line the
            // engine has since changed its mind about, or on a line that no longer exists, is
            // something only the person who made it can resolve. Silence would read as agreement.
            foreach ($overrideNotes as $note) {
                $this->audit->log('updated', 'Payroll', $note, $run);
            }

            // Say plainly whose pay this re-run left alone.
            if ($skippedDisbursed !== []) {
                $this->audit->log(
                    'updated',
                    'Payroll',
                    count($skippedDisbursed) . ' already-disbursed payslip(s) were left unchanged by this recompute',
                    $run,
                );
            }

            // And TELL THE CALLER, not just the audit log.
            $run->skippedDisbursedCount = count($skippedDisbursed);

            // One summary line for the reimbursements this run auto-paid.
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

        // One line so a slow run is visible without guesswork. The whole run is a
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
     * WHAT RE-RUNNING THIS PERIOD WOULD CHANGE. Computed for real, saved nowhere.
     *
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function rerunPreview(PayrollRun $run, User $actor): array
    {
        $this->assertRerunnable($run);

        $before = $run->payslips()->with('employee.user')->get()->keyBy('employee_id');

        // The rollback is in `finally` rather than after the call: a run that throws part-way
        // (a missing contribution rate, a minimum-wage refusal) must not leave the savepoint open.
        try {
            DB::beginTransaction();

            $fresh = $this->run(
                $run->cycle_start->toDateString(),
                $run->cycle_end->toDateString(),
                $run->frequency,
                $actor,
            );

            // Read INSIDE the transaction, then keep only scalars: the models die with the rollback.
            $after = $fresh->payslips()->with('employee.user')->get()->keyBy('employee_id');
            $result = $this->rerunDiff($before, $after, $run);
        } finally {
            DB::rollBack();
        }

        // The in-memory model was walked through `processing` → `completed` by the run that has just
        // been rolled back, so anything serialising it afterwards would read invented state.
        $run->refresh();

        return $result;
    }

    /** Recompute a period that has already been run, with a stated reason. */
    public function rerunRun(PayrollRun $run, string $reason, User $actor): PayrollRun
    {
        $this->assertRerunnable($run);

        return $this->run(
            $run->cycle_start->toDateString(),
            $run->cycle_end->toDateString(),
            $run->frequency,
            $actor,
            $reason,
        );
    }

    /** Only an ordinary cycle can be recomputed from its own dates. */
    private function assertRerunnable(PayrollRun $run): void
    {
        if ($run->isLocked()) {
            throw ValidationException::withMessages([
                'run' => [
                    'This payroll run is locked (' . $run->locked_at->format('M j, Y')
                        . ') and can no longer be recomputed. Unlock it first.',
                ],
            ]);
        }

        if ($run->type !== 'regular') {
            throw ValidationException::withMessages([
                'run' => [match ($run->type) {
                    'thirteenth_month' => 'A 13th-month run is recomputed from the 13th-month screen, not as a pay period.',
                    'final_pay' => 'A final pay is recomputed from the employee it belongs to.',
                    default => 'Only an ordinary pay period can be recomputed.',
                }],
            ]);
        }

        if ($run->status !== 'completed') {
            throw ValidationException::withMessages([
                'run' => ['This run has not finished computing yet.'],
            ]);
        }
    }

    /**
     * One row per employee: what the period says now, what it would say, and why it would not move.
     *
     * @param  \Illuminate\Support\Collection<int, Payslip>  $before
     * @param  \Illuminate\Support\Collection<int, Payslip>  $after
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function rerunDiff($before, $after, PayrollRun $run): array
    {
        $figures = fn (?Payslip $p) => $p === null ? null : [
            'gross' => round((float) $p->gross, 2),
            'deductions' => round((float) $p->total_deductions, 2),
            'net' => round((float) $p->net_pay, 2),
        ];

        $rows = [];

        foreach ($before->keys()->merge($after->keys())->unique() as $employeeId) {
            $was = $before->get($employeeId);
            $now = $after->get($employeeId);

            // The re-run left this one alone because the money has already gone out. Reported from
            // the STORED status, not from the absence of a difference: a disbursed payslip whose
            // figures happen to be unchanged is a different statement from one that was skipped.
            $skipped = $was?->status === 'disbursed';

            $change = match (true) {
                $skipped => 'skipped',
                $was === null => 'added',
                $now === null => 'removed',
                abs((float) $was->net_pay - (float) $now->net_pay) > 0.005
                    || abs((float) $was->gross - (float) $now->gross) > 0.005 => 'changed',
                default => 'unchanged',
            };

            $rows[] = [
                'employee_id' => $employeeId,
                'employee' => ($was ?? $now)?->employee?->user?->name ?? ($was ?? $now)?->employee?->name,
                'status' => $was?->status,
                'change' => $change,
                'before' => $figures($was),
                'after' => $skipped ? $figures($was) : $figures($now),
                'delta' => $skipped || $was === null || $now === null
                    ? 0.0
                    : round((float) $now->net_pay - (float) $was->net_pay, 2),
            ];
        }

        // What moved, first. A period of forty employees where two figures change is the normal
        // case, and it is the two that the decision rests on.
        $rank = ['changed' => 0, 'added' => 1, 'removed' => 2, 'skipped' => 3, 'unchanged' => 4];
        usort($rows, fn ($a, $b) => [$rank[$a['change']], $a['employee'] ?? ''] <=> [$rank[$b['change']], $b['employee'] ?? '']);

        $netAfter = array_sum(array_map(fn ($r) => $r['after']['net'] ?? 0.0, $rows));
        $netBefore = array_sum(array_map(fn ($r) => $r['before']['net'] ?? 0.0, $rows));

        return [
            'rows' => $rows,
            'meta' => [
                'net_before' => round($netBefore, 2),
                'net_after' => round($netAfter, 2),
                'delta' => round($netAfter - $netBefore, 2),
                'changed' => count(array_filter($rows, fn ($r) => $r['change'] === 'changed')),
                'added' => count(array_filter($rows, fn ($r) => $r['change'] === 'added')),
                'removed' => count(array_filter($rows, fn ($r) => $r['change'] === 'removed')),
                'skipped' => count(array_filter($rows, fn ($r) => $r['change'] === 'skipped')),
                'unchanged' => count(array_filter($rows, fn ($r) => $r['change'] === 'unchanged')),
                // Re-running is what clears a stale tax mark, so the dialog can say the recompute
                // has a second purpose beyond whatever the figures show.
                'clears_tax_stale' => $run->isTaxStale(),
            ],
        ];
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
            // The TOTALS above intentionally span every completed run (13th-month pay
            // is income and belongs in a year-to-date figure). The 13th-month PROJECTION must
            // not: measured on regular runs only, or last year's payment inflates this year's
            // entitlement. Same basis as the report and the run.
            'thirteenth_month' => round(
                (float) $this->thirteenthMonthBasisFor($year)
                    ->where('hr_payslips.employee_id', $employee->id)
                    ->sum('hr_payslips.base_salary') / 12,
                2,
            ),
        ];
    }

    /** Render one payslip to a DomPDF document — the same document the UI shows. */
    public function payslipPdf(Payslip $payslip): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('hr::payslip', $this->payslipViewData($payslip));
    }

    /**
     * What `hr::payslip` renders from. Separate from payslipPdf() so a test can render the
     * document as HTML and read it — the PDF's text is compressed and not searchable.
     *
     * @return array<string, mixed>
     */
    public function payslipViewData(Payslip $payslip, ?Request $request = null): array
    {
        // The corrections are loaded HERE, not left to the caller: the resource renders them
        // `whenLoaded`, so a caller that forgot would produce a PDF whose net silently includes
        // corrections the document never mentions.
        $payslip->loadMissing([
            'payrollRun.runner', 'employee.user', 'employee.department', 'amendments.creator',
        ]);

        return [
            // resolve(), not toArray(): the resource's role-gated block is a conditional merge,
            // and only resolve() runs the filter that either splices it in or drops it.
            'p' => (new PayslipResource($payslip))->resolve($request ?? request()),
            'amt' => fn ($n) => number_format((float) $n, 2),
            'fmt' => fn ($d) => $d ? Carbon::parse($d)->format('F d, Y') : '—',
            'stamp' => fn ($d) => $d
                ? Carbon::parse($d)->timezone(config('app.timezone_local'))->format('F d, Y · g:i A')
                : '—',
        ];
    }

    /**
     * A payroll run as CSV — one row per employee (basic → net, with each statutory
     * deduction) plus a TOTAL row that doubles as the remittance summary. Audit-logged.
     */
    public function runCsv(PayrollRun $run): string
    {
        $register = $this->registerRows($run);

        // No BOM: this export is machine-read as often as it is opened by a person, and adding
        // one now would change every existing consumer's first column header.
        $csv = CsvWriter::render([$register['columns'], ...$register['rows']], bom: false);

        $this->audit->log(
            'exported',
            'Payroll',
            'Exported payroll run CSV for ' . $run->cycle_start?->format('Y-m-d') . ' – ' . $run->cycle_end?->format('Y-m-d'),
            $run,
        );

        return $csv;
    }

    /**
     * THE REGISTER — the widest honest description of a run, and the body of both exports.
     *
     * @return array{columns: list<string>, rows: list<list<float|int|string|null>>}
     */
    private function registerRows(PayrollRun $run): array
    {
        $run->loadMissing(['payslips.employee.user', 'payslips.employee.department']);

        $sum = ['base' => 0.0, 'allow' => 0.0, 'holiday' => 0.0, 'ot' => 0.0, 'nd' => 0.0, 'rd' => 0.0, 'reimb' => 0.0, 'sss' => 0.0, 'ph' => 0.0, 'pg' => 0.0, 'wht' => 0.0, 'other' => 0.0, 'er_sss' => 0.0, 'er_ph' => 0.0, 'er_pg' => 0.0];

        // Every row goes through CsvWriter, which neutralises leading =, +, -, @, tab
        // and CR. Employee and department names are free text a person controls, and this file is
        // opened in Excel on a finance workstation.
        $rows = [];
        $columns = [
            'Employee', 'Department', 'Base', 'Allowances', 'Holiday Premium', 'Overtime Pay', 'Night Diff', 'Rest Day', 'Reimbursements', 'Gross',
            'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Other Deductions', 'Total Deductions', 'Net Pay',
            // This file doubles as the remittance summary, and a per-employee override
            // moves the employee half only. Without this column the mismatched pair reads as a
            // proper contribution and gets filed as one.
            'Employer SSS', 'Employer PhilHealth', 'Employer Pag-IBIG', 'Overridden', 'Status',
        ];

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

            $rows[] = [
                $p->employee->user?->name ?? $p->employee->name,
                $p->employee->department?->name,
                $p->base_salary, $p->allowances, $p->holiday_premium, $p->overtime_pay, $p->night_diff_pay, $p->rest_day_premium, $p->reimbursements, $p->gross,
                $p->sss, $p->philhealth, $p->pagibig, $p->withholding_tax, $p->other_deductions,
                $p->total_deductions, $p->net_pay,
                $p->employer_sss, $p->employer_philhealth, $p->employer_pagibig,
                implode(' ', array_map(
                    fn ($t) => match ($t) {
                        'sss' => 'SSS',
                        'philhealth' => 'PhilHealth',
                        'pagibig' => 'Pag-IBIG',
                        default => $t,
                    },
                    $p->contribution_overrides ?? [],
                )),
                $p->status,
            ];
        }

        $rows[] = [
            'TOTAL', '',
            round($sum['base'], 2), round($sum['allow'], 2), round($sum['holiday'], 2), round($sum['ot'], 2), round($sum['nd'], 2), round($sum['rd'], 2), round($sum['reimb'], 2), (float) $run->total_gross,
            round($sum['sss'], 2), round($sum['ph'], 2), round($sum['pg'], 2), round($sum['wht'], 2), round($sum['other'], 2),
            (float) $run->total_deductions, (float) $run->total_net,
            round($sum['er_sss'], 2), round($sum['er_ph'], 2), round($sum['er_pg'], 2), '',
        ];

        return [
            'columns' => $columns,
            'rows' => $rows,
            // Which columns are prose. Everything else is a figure — and it has to reach the
            // workbook AS a figure: Eloquent hands decimals back as strings, and a money column
            // written as text is one Excel will right-align, refuse to sum, and quietly mislead
            // whoever tries.
            'text' => array_keys(array_intersect($columns, ['Employee', 'Department', 'Overridden', 'Status'])),
        ];
    }

    /** THE BREAKDOWN SHEET'S COLUMNS — label, cell treatment, and how wide it has to be to read. */
    private const BREAKDOWN_COLUMNS = [
        ['label' => 'Employee', 'type' => 'text', 'width' => 26.0],
        ['label' => 'Department', 'type' => 'text', 'width' => 18.0],
        ['label' => 'Basic Pay', 'type' => 'money', 'width' => 13.0],
        ['label' => 'Allowances', 'type' => 'money', 'width' => 12.0],
        ['label' => 'Overtime', 'type' => 'money', 'width' => 11.0],
        ['label' => 'Night Diff', 'type' => 'money', 'width' => 11.0],
        ['label' => 'Rest Day', 'type' => 'money', 'width' => 11.0],
        ['label' => 'Holiday', 'type' => 'money', 'width' => 11.0],
        ['label' => 'Reimbursements', 'type' => 'money', 'width' => 14.0],
        ['label' => 'Other Earnings', 'type' => 'money', 'width' => 13.0],
        ['label' => 'Gross Pay', 'type' => 'money', 'width' => 14.0],
        ['label' => 'Absences', 'type' => 'charge', 'width' => 11.0],
        ['label' => 'Half-Days', 'type' => 'charge', 'width' => 11.0],
        ['label' => 'Undertime', 'type' => 'charge', 'width' => 11.0],
        ['label' => 'Late', 'type' => 'charge', 'width' => 10.0],
        ['label' => 'Work Suspension', 'type' => 'charge', 'width' => 14.0],
        ['label' => 'Leave Without Pay', 'type' => 'charge', 'width' => 16.0],
        ['label' => 'Other Attendance', 'type' => 'charge', 'width' => 15.0],
        ['label' => 'SSS', 'type' => 'charge', 'width' => 10.0],
        ['label' => 'PhilHealth', 'type' => 'charge', 'width' => 11.0],
        ['label' => 'Pag-IBIG', 'type' => 'charge', 'width' => 11.0],
        ['label' => 'Withholding Tax', 'type' => 'charge', 'width' => 13.0],
        ['label' => 'Other Deductions', 'type' => 'charge', 'width' => 14.0],
        ['label' => 'Total Deductions', 'type' => 'charge', 'width' => 14.0],
        ['label' => 'Net Pay', 'type' => 'money', 'width' => 14.0],
        ['label' => 'Status', 'type' => 'status', 'width' => 11.0],
    ];

    /**
     * A payroll run as a styled workbook — the copy meant to be READ, next to the CSV's copy meant
     * to be parsed. Audit-logged like the CSV, because it discloses exactly as much.
     *
     * @return array{path: string, name: string}
     */
    public function runXlsx(PayrollRun $run): array
    {
        // `amendments` is eager-loaded because the deduction columns are built by the same
        // PayslipDocument code the payslip document uses, and a correction HR typed is a PINNED
        // row there. Without the ledger the workbook would print the engine's figures beside a
        // payslip showing HR's — the same figure, disagreeing with itself in two files.
        $run->loadMissing([
            'payslips.employee.user',
            'payslips.employee.department',
            'payslips.amendments',
        ]);

        $columns = self::BREAKDOWN_COLUMNS;
        $rows = [];
        $sums = array_fill(0, count($columns), 0.0);

        foreach ($run->payslips as $payslip) {
            $row = $this->breakdownRow($payslip);
            $rows[] = $row;

            foreach ($columns as $i => $column) {
                if (in_array($column['type'], ['money', 'charge'], true)) {
                    $sums[$i] = round($sums[$i] + (float) $row[$i], 2);
                }
            }
        }

        // The total row and the cards are summed from the COLUMNS ABOVE THEM, not from the run's
        // stored totals. The two agree for an ordinary run — but the sheet showing basic pay gross
        // of absences has to foot to its own arithmetic, and a header card contradicting the table
        // under it is the specific failure this export exists to avoid.
        $totals = $sums;
        $totals[0] = 'TOTAL PAYROLL';
        $totals[1] = '';
        $totals[count($columns) - 1] = '';

        $grossColumn = array_search('Gross Pay', array_column($columns, 'label'), true);
        $deductionsColumn = array_search('Total Deductions', array_column($columns, 'label'), true);
        $netColumn = array_search('Net Pay', array_column($columns, 'label'), true);

        $path = tempnam(sys_get_temp_dir(), 'payroll-');

        PayrollXlsxWriter::write(
            $path,
            [
                'title' => 'Payroll Breakdown • ' . $this->periodLabel($run),
                'kpis' => [
                    ['label' => 'EMPLOYEES', 'value' => count($rows), 'type' => 'count'],
                    ['label' => 'GROSS PAYROLL', 'value' => $sums[$grossColumn], 'type' => 'money'],
                    ['label' => 'TOTAL DEDUCTIONS', 'value' => $sums[$deductionsColumn], 'type' => 'charge'],
                    ['label' => 'NET PAYROLL', 'value' => $sums[$netColumn], 'type' => 'money'],
                ],
                'columns' => $columns,
                'rows' => $rows,
                'totals' => $totals,
            ],
            $this->registerRows($run),
        );

        $this->audit->log(
            'exported',
            'Payroll',
            'Exported payroll run workbook for ' . $run->cycle_start?->format('Y-m-d') . ' – ' . $run->cycle_end?->format('Y-m-d'),
            $run,
        );

        return [
            'path' => $path,
            'name' => 'VITE_SEO_Payroll_Breakdown_' . Str::of($this->periodLabel($run))
                ->replace(['–', ' '], ['-', '_'])
                ->replace(',', '')
                ->toString() . '.xlsx',
        ];
    }

    /**
     * One employee's line on the breakdown sheet.
     *
     * @return list<float|int|string|null>
     */
    private function breakdownRow(Payslip $payslip): array
    {
        $slip = $payslip->attributesToArray();
        $f = fn (string $field) => round((float) ($slip[$field] ?? 0), 2);

        // The corrections HR pinned to a printed line, in the shape PayslipDocument measures
        // against — same source as PayslipResource::lineOverrides().
        $overrides = [];

        foreach ($payslip->amendments as $amendment) {
            if ($amendment->target_key === null) {
                continue;
            }

            $overrides[$amendment->target_key] = [
                'amount' => $amendment->override_amount === null ? null : (float) $amendment->override_amount,
            ];
        }

        $charges = [];

        foreach (PayslipDocument::deductionRows($slip, $overrides) as $row) {
            $charges[$row['key']] = (float) $row['amount'];
        }

        $charge = fn (string $key) => round($charges[$key] ?? 0.0, 2);

        $absence = $f('absence_deduction');

        // The residual, NOT a sum of the leftover kinds: the named five plus LWOP are what the
        // sheet has columns for, and every attendance charge apportions out of `absence_deduction`.
        // Taking the difference is what guarantees the row foots even when a kind appears that
        // nobody has given a column yet (holiday forfeited, outside employment, a tardiness
        // penalty) — those would otherwise vanish and the row would be short by their amount.
        $otherAttendance = round($absence - (
            $charge('absent') + $charge('half_day') + $charge('undertime')
            + $charge('late') + $charge('suspended_unpaid') + $charge('leave_unpaid')
        ), 2);

        $gross = round($f('gross') + $absence, 2);
        $otherEarnings = round($gross - (
            $f('period_base') + $f('allowances') + $f('overtime_pay') + $f('night_diff_pay')
            + $f('rest_day_premium') + $f('holiday_premium') + $f('reimbursements')
        ), 2);

        return [
            $payslip->employee?->user?->name ?? $payslip->employee?->name ?? '—',
            $payslip->employee?->department?->name ?? '',
            $f('period_base'),
            $f('allowances'),
            $f('overtime_pay'),
            $f('night_diff_pay'),
            $f('rest_day_premium'),
            $f('holiday_premium'),
            $f('reimbursements'),
            $otherEarnings,
            $gross,
            $charge('absent'),
            $charge('half_day'),
            $charge('undertime'),
            $charge('late'),
            $charge('suspended_unpaid'),
            $charge('leave_unpaid'),
            $otherAttendance,
            $f('sss'),
            $f('philhealth'),
            $f('pagibig'),
            $f('withholding_tax'),
            $f('other_deductions'),
            round($f('total_deductions') + $absence, 2),
            $f('net_pay'),
            Str::headline((string) $payslip->status),
        ];
    }

    /** The cycle as a person writes it: "August 1–15, 2026", or spelled out across a boundary. */
    private function periodLabel(PayrollRun $run): string
    {
        $start = $run->cycle_start;
        $end = $run->cycle_end;

        if ($start === null || $end === null) {
            return (string) ($start ?? $end)?->format('F j, Y');
        }

        if ($start->isSameDay($end)) {
            return $start->format('F j, Y');
        }

        if ($start->isSameMonth($end)) {
            return $start->format('F j') . '–' . $end->format('j, Y');
        }

        if ($start->year === $end->year) {
            return $start->format('F j') . ' – ' . $end->format('F j, Y');
        }

        return $start->format('F j, Y') . ' – ' . $end->format('F j, Y');
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
        // The same basis the run itself uses, so the accrual report and the payment
        // can never disagree. This used to filter status but not type, so last year's 13th-month
        // run counted toward this year's accrual.
        $rows = $this->thirteenthMonthBasisFor($year)
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
