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
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\SharedLeaveBalance;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\RetroFlag;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Payroll engine (v2). Pay is attendance-connected — absences are deducted at the
 * daily rate from actual hr_attendance records. Statutory deductions are driven by
 * the editable Company → Benefits config + each employee's enrolment checklist
 * (ContributionService), NOT hardcoded formulas.
 *
 * THE RATES ARE NO LONGER PLACEHOLDERS. This docblock claimed the SSS config was "a placeholder
 * rate until the official bracket table is supplied" long after B3b/B8 supplied it: the published
 * 61-band schedule is seeded from Circular 2024-006 and machine-imported rather than typed. The
 * withholding table stopped being hardcoded too (FIN-027) — it is dated data in `hr_tax_versions`.
 * The comment was left saying the opposite, which is worse than no comment: it invites the next
 * reader to distrust figures that are in fact correct, or to "fix" them.
 *
 * EVERY PRICING INPUT IS EFFECTIVE-DATED, resolved as of the cycle end: salary
 * (`hr_salary_histories`), statutory rates (`hr_contribution_versions`), allowances
 * (`hr_benefit_program_versions`), the attendance policy (`hr_attendance_policy_versions`), payroll
 * settings (`hr_payroll_setting_versions`) and the tax table (`hr_tax_versions`). Re-running an old
 * period therefore reprices it at what it actually charged, not at today's configuration.
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
     * Single source of truth (Slice A, 2026-07-27): payslipFor() and holidayPremiumFor() both
     * price a day through here, so absence, late/undertime, and holiday premium can never
     * disagree. Default basis reproduces the pre-Slice-A number.
     */
    /**
     * Is this employee's derived daily rate below the regional minimum wage? (FIN-030)
     *
     * WHY THIS IS A WARNING AND NOT VALIDATION. The floor varies by region, by industry and by
     * establishment size, and it changes on DOLE wage orders with their own dates. A hard block
     * would be wrong for the cases this system cannot see — a legitimately exempt arrangement, a
     * part-time schedule, a probationary rate under a different order — and being wrong sometimes is
     * how a block gets routed around. So it informs; HR decides.
     *
     * WHAT MAKES IT WORTH SURFACING AT ALL: the daily rate is DERIVED (salary ÷ the basis on the
     * attendance policy), so nobody typing a monthly figure can see what it implies per day. That is
     * the gap — not that HR would knowingly pay below the floor.
     *
     * WHY THE RATE IS RETURNED EVEN WITH NO FLOOR CONFIGURED (FIN-030b). The first version returned
     * null unless a floor was set, so the screen showed nothing at all — and the one number nobody
     * can work out for themselves stayed invisible in exactly the case where it matters most,
     * before anyone has thought about the wage order. The rate is the answer to "what is one of
     * this person's days worth", which the basis alone decides; the floor only decides whether that
     * answer is a problem. `minimum` stays null when none is configured, and null warns about
     * nothing — inventing a figure would be guessing at a legal threshold, the same refusal the
     * de-minimis migration made (FIN-024).
     *
     * `below` is the arithmetic truth. `flagged` is `below` minus the people someone has explicitly
     * excused (FIN-030b), and it is the one every list and gate should read: a warning that is
     * permanently wrong for the same five part-timers is a warning everyone learns to scroll past.
     *
     * Returns null only when the rate itself cannot be derived.
     *
     * @return array{
     *     daily_rate: float, basis: string, divisor: int|null, workdays: int,
     *     minimum: float|null, below: bool, flagged: bool, shortfall: float|null,
     *     compliant_salary: float|null, exempt: bool, exempt_reason: string|null
     * }|null
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
     * Null when the rate cannot be derived at all (no scheduled workdays) — a schedule problem,
     * which payroll itself reports with a far better message than a wage warning could give.
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
     * the proposal is produced by the same arithmetic that produced the breach (FIN-030b).
     *
     * Rounded UP to the centavo. Rounding to nearest would let a proposal land a hair under the
     * floor after dailyRateFor()'s own rounding, which is the one outcome an adjustment must never
     * have: a raise that leaves the person still non-compliant.
     *
     * Under `period_workdays` the answer depends on the period's workday count, so it clears the
     * floor for THIS period and is a close approximation elsewhere. Under `fixed_divisor` it is
     * exact all year, which is what a fixed divisor is for.
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
     * Extracted (FIN-030b) so the pre-run minimum-wage review asks EXACTLY the question the run
     * asks. A review over a nearly-identical population is worse than none: it clears people the
     * run then pays, and the difference would only ever show up as a compliance gap nobody
     * could account for.
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
     * Everyone this run would pay whose derived daily rate falls under the floor (FIN-030b).
     *
     * WHY IT IS ASKED AT THE RUN AND NOT ONLY ON THE PROFILE. A band on a profile page is seen by
     * whoever happens to open that profile, which for a compliant-looking employee is nobody. The
     * moment the answer actually matters is the moment money is about to be committed for a period,
     * so the same question is asked there, over exactly the population the run will pay.
     *
     * Evaluated against THIS cycle — the settings in force for it, the policy as it stood at its
     * end, and its own workday count — so a review of an old period judges it by the floor that
     * applied then rather than today's.
     *
     * @return array{
     *     minimum: float|null, basis: string|null, divisor: int|null,
     *     cycle: array{start: string, end: string}, employees: list<array<string, mixed>>,
     *     checked: int, flagged_count: int, exempt_count: int
     * }
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
     * Raise the flagged employees to the salary that clears the floor (FIN-030b).
     *
     * WHY THIS IS A BUTTON AND NOT A BACKGROUND JOB. A wage order raises what the company MUST pay;
     * it does not raise anybody's salary by itself. Salary is a contractual term, so the change
     * needs an approver, a date it takes effect from, and a record — which is precisely what
     * routing it through EmployeeService::update() buys: the same SalaryHistory row, the same
     * audit entry and the same repricing guard as any other raise. A job that rewrote pay on its
     * own would leave nobody able to answer who decided it.
     *
     * The system's job is to make the correct number obvious and one click away. Committing it
     * stays a person's decision, and the ids are passed in explicitly so it is never "everyone the
     * screen happened to be showing".
     *
     * Back pay is NOT computed here. When the effective date reaches into a period that was
     * already settled, the difference is recorded as a retro flag (FIN-009) — the queue HR already
     * works from, settled by an adjustment run (FIN-010). Paying it silently from inside a salary
     * edit would be money moving with no run behind it.
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

        // Allowances are monthly, non-taxable de-minimis pay. Spread across the year's periods
        // like base, but NOT absence-prorated (they're fixed benefits, not attendance-earned).
        //
        // Slice B1 — read from Company → Benefits, not the three `hr_employees` columns. Those
        // columns and the Benefits page disagreed (₱42,000 vs ₱77,000 a month company-wide) and
        // Gas Allowance had no column at all, so it could never be paid however HR configured it.
        // The backfill migration pinned each employee's old column value onto their enrolment, so
        // this change paid exactly what the columns did on the first run.
        // Slice B11 — priced as of the CYCLE END, like salary (13c) and the statutory rates
        // (B3a). Re-running August after a September rise used to pay September's figure.
        $pricedAsOf = $dist['cycle_end'] ?? null;
        $monthlyAllowance = $this->allowances->monthlyFor($employee, $pricedAsOf);
        $perPeriodAllowance = $periodsPerYear > 0 ? round(($monthlyAllowance * 12) / $periodsPerYear, 2) : 0.0;

        // Slice B10 — PART-MONTHS. A mid-cycle hire or leaver used to receive a whole period's
        // allowance while their basic pay was prorated for the days they were actually employed.
        // Someone starting on the 25th got the full month. It is wrong on the first and last
        // payslip anybody gets — the two most closely read payslips in a career.
        //
        // NOT the same as absence proration, which stays deliberately off (an allowance is a fixed
        // benefit, not attendance-earned). This follows the EMPLOYMENT WINDOW only: days before a
        // hire date or after a last working day were never owed.
        //
        // Management is excluded to match their basic pay, which `unpaidDaysDetailFor()` never
        // prorates either — prorating one and not the other would make their payslip inconsistent
        // with itself. (Whether a management hire should get a full first month is a separate
        // question, and an older one than this slice.)
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
        // period (Slice B3a) — not whatever they are today.
        $contribConfig = $this->contributions->effectiveFor($employee, $dist['cycle_end'] ?? null);

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
        // reduced, so we don't over-withhold a low-earning period.
        //
        // Slice 10 — PREMIUM PAY IS TAXABLE. Holiday premium, overtime, the night differential
        // and rest-day premium are all taxable compensation in PH, and all four were excluded
        // from this base. That was harmless while every one of them was always ₱0; now that they
        // actually pay, leaving them out understates withholding. Reimbursements stay out (a
        // reimbursement is not income).
        $taxablePeriodPay = $basePay + $holidayPremium + $overtimePay + $nightDiffPay + $restDayPremium;
        $taxableMonthly = $periodsPerYear > 0 ? round($taxablePeriodPay * $periodsPerYear / 12, 2) : 0.0;

        // Slice B4 — the two kinds of allowance are taxed differently, so split before taxing.
        // Payroll used to treat EVERY allowance as de-minimis regardless of the switch HR sets on
        // the programme, so marking one "taxable" changed nothing on the payslip and the company
        // quietly under-withheld. Gross pay is unaffected either way: the split only decides how
        // much of the same money is compensation.
        $allowanceSplit = $this->allowances->monthlyTaxBucketsFor($employee, $pricedAsOf);

        // Slice B10 — a part-period employee is taxed on the allowance they were actually paid.
        // Without this they would be withheld on a full month of a taxable allowance they only
        // received part of, which is the same error the proration above exists to fix.
        if ($employmentFraction < 1.0) {
            $allowanceSplit = array_map(
                fn (float $v) => round($v * $employmentFraction, 2),
                $allowanceSplit,
            );
        }

        // Slice B19 — THE STATUTORY CONTRIBUTION BASE, which is no longer one number.
        //
        // WHAT WAS WRONG. Every contribution was charged on `$monthlyBase` — the profile salary,
        // untouched by absences, lates, overtime, allowances or any premium line. An employee with
        // a week of unpaid leave paid SSS on money they never received; one with heavy overtime
        // paid nothing on it. Withholding tax has tracked actual earnings since Slice 10, so the
        // two bases sat a dozen lines apart and disagreed.
        //
        // WHY BOTH FIGURES ARE COMPUTED AND THE VERSION PICKS. The three agencies do not measure
        // the same thing: PhilHealth is the fixed BASIC monthly salary by law (Circular 2019-0009,
        // which expressly excludes allowances, overtime and COLA), while SSS charges "compensation"
        // (RA 11199 §8(f)) and Pag-IBIG monthly compensation. One rule for all three would be
        // wrong for at least one of them, so each `hr_contribution_versions` row names its own
        // `salary_basis` and `computePayslip()` reads the figure that version asks for.
        //
        // MONTHLY-EQUIVALENT, NOT THIS PERIOD'S CASH. Contributions are monthly amounts that
        // `timingFactor()` then splits across the month's cutoffs, and the SSS bands are printed
        // against a monthly salary — so a semi-monthly period's earnings have to be annualised
        // back up before they can meet a bracket. Same conversion `$taxableMonthly` above does,
        // and for the same reason.
        //
        // KNOWN LIMITATION, stated because it is a real one: with `deduction_timing = every`, two
        // cutoffs of one month are annualised independently, so a period with overtime and a period
        // with absences can land in different SSS bands and the month's total will not equal any
        // single printed band. Setting the timing to `first` or `last` avoids it by charging the
        // whole monthly contribution on one cutoff.
        //
        // NO CIRCULARITY, despite what "based on net pay" sounds like: this is gross EARNINGS,
        // before any deduction. Nothing in it depends on the contributions computed from it.
        $contributableAllowance = $this->allowances->monthlyContributableFor($employee, $pricedAsOf);

        // The same part-period scaling the paid allowance gets above — an employee who was only
        // employed for half the cycle received half the allowance and is charged on half of it.
        if ($employmentFraction < 1.0) {
            $contributableAllowance = round($contributableAllowance * $employmentFraction, 2);
        }

        // `$taxablePeriodPay` is exactly the right set of earnings lines: base pay net of absences
        // and lates, plus the four premium lines. Reimbursements are excluded there because a
        // reimbursement is not income, which makes it not remuneration here either.
        $contributionBases = [
            'basic' => round($monthlyBase, 2),
            'earnings' => round(
                ($periodsPerYear > 0 ? $taxablePeriodPay * $periodsPerYear / 12 : 0.0)
                    + $contributableAllowance,
                2,
            ),
        ];

        // A taxable allowance is compensation in full — no ceiling applies to it at all. Already a
        // MONTHLY figure, which is what the withholding base is measured in.
        $taxableMonthly += $allowanceSplit['taxable'];

        // Slice B16 — the amount above each benefit's OWN statutory ceiling is not taxed here. It
        // joins the "13th month pay and other benefits" bucket, which is exempt up to ₱90,000 a
        // year (TRAIN); only what is over that figure is compensation.
        //
        // The old code taxed the excess immediately against a single pooled cap, which over-withheld
        // for everybody whose benefits sit under ₱90,000 — the normal case — and applied a rule
        // (one aggregate de-minimis cap) that does not exist.
        //
        // 13th-month pay is NOT added here, and the reason CHANGED. This used to say the system
        // "reports it but never pays it" — true when written, false since B17 added
        // `runThirteenthMonth()`. It is excluded now because the 13th-month run computes its own
        // share of the ₱90,000 bucket and withholds on its own excess, reading back the
        // `other_benefits` this line records. Adding it here as well would count it twice.
        $taxableMonthly += $this->otherBenefitsTaxableMonthly(
            $allowanceSplit['other_benefits'] * 12,
            $dist['other_benefits_annual_cap'] ?? null,
        );

        // Slice B17 — this period's contribution to the ₱90,000 bucket, recorded on the payslip so
        // December can add up what was actually PAID during the year. Recomputing it from the
        // catalogue at year-end would let a mid-year allowance change rewrite history.
        $perPeriodOtherBenefits = $periodsPerYear > 0
            ? round(($allowanceSplit['other_benefits'] * 12) / $periodsPerYear, 2)
            : 0.0;

        // Slice 11a — recurring benefit shares (HMO, union dues) from Company → Benefits.
        // `count` is how many periods this calendar month has, so a MONTHLY share is split
        // the same way statutory contributions are.
        // The cycle dates are passed so an enrolment that starts or ends mid-year is charged only
        // for the periods it actually covers (Slice B2).
        $otherDeductions = $this->benefitDeductions->forPeriod(
            $employee,
            max(1, (int) ($dist['count'] ?? 1)),
            $dist['cycle_start'] ?? null,
            $dist['cycle_end'] ?? null,
        );

        $slip = $this->computePayslip($basePay, $reimb, $perPeriodAllowance, $contributionBases, $taxableMonthly, $contribConfig, $overrides, $dist, $holidayPremium, $overtimePay, $nightDiffPay, $restDayPremium, $otherDeductions);

        // The payslip shows the FULL period basic with the absence charged as its own deduction
        // line, rather than a basic figure quietly net of it. `absence_deduction` is the charge
        // ACTUALLY applied, which is not `unpaidDays × dailyRate` whenever basePay clamped at
        // zero — deriving it at display time would then subtract more than was taken and the
        // document wouldn't add up to net pay.
        // Slice B17 — the two figures December needs, and one it cannot derive later.
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

        return $slip;
    }

    /**
     * This period's allowances split into the components that make them up, snapshot onto the
     * payslip so the document can name them instead of showing one opaque "Allowances" figure.
     *
     * SNAPSHOT, not a join. Reading the employee's live allowance columns at display time would
     * silently re-label every historical payslip the moment someone's transport allowance
     * changes — the same reason `policy_snapshot` exists on the run.
     *
     * The residual exists because `allowances` is `round(monthlyTotal × 12 ÷ periods)` — the sum
     * is rounded, not the parts — so independently rounded components can miss it by a centavo.
     * It is absorbed into the largest line, which keeps the breakdown summing to the stored
     * `allowances` exactly without perturbing the figure payroll actually paid.
     *
     * @return list<array{name:string, amount:float}>
     */
    private function allowanceBreakdown(
        Employee $employee,
        int $periodsPerYear,
        float $perPeriodAllowance,
        float $employmentFraction = 1.0,
        ?string $pricedAsOf = null,
    ): array {
        if ($periodsPerYear <= 0 || $perPeriodAllowance <= 0) {
            return [];
        }

        // Slice B1 — the lines come from the employee's enrolled programmes, not the three
        // hard-coded `hr_employees` columns. That is what lets a fourth allowance (Gas) appear on
        // a payslip at all, and it means the names shown are the ones HR actually configured.
        $lines = [];
        foreach ($this->allowances->linesFor($employee, $pricedAsOf) as $line) {
            // Slice B10 — scaled by the same part-period fraction as the total, or the lines
            // would sum past it and the residual would swallow the whole proration.
            $perPeriod = round((($line['amount'] * 12) / $periodsPerYear) * $employmentFraction, 2);
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
     * the withholding base (Slice 10).
     *
     * De-minimis benefits are tax-free only up to a ceiling; the excess is compensation. Each
     * benefit has its own BIR limit, which we deliberately do NOT hardcode — a table of per-item
     * limits we can't keep current would be worse than no table. HR sets one annual ceiling for
     * the total instead. A null ceiling means "all of it is tax-free", exactly as before.
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
            // Slice B3a — the dated rate versions actually in force for this cycle. The
            // `contributions` block below is the enrolment config; this is what priced it.
            'contribution_versions' => $this->contributions->versionsAsOf($cycleEnd)
                ->map(fn ($v) => $v->only([
                    'type', 'basis', 'employee_rate', 'employer_rate', 'salary_floor', 'salary_cap',
                    'employee_amount_cap', 'employer_amount_cap', 'fixed_employer_add_on', 'add_on_min_salary',
                ]) + ['effective_from' => $v->effective_from?->toDateString()])
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
            //
            // Built from the model's fillable, NOT hand-listed. The five keys here used to be
            // written out by hand, and Slice 10 added the benefits ceiling to the settings two
            // slices later without adding it here — so a payslip could not explain its
            // taxable-allowance line. Slice 8 existed to stop exactly this drift and had fixed
            // only its attendance-policy half.
            'payroll_setting' => $setting->only($setting->getFillable()),
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
     * `allowances` the non-taxable de-minimis pay that go into gross; `contribBases` are the
     * candidate monthly salary figures statutory CONTRIBUTIONS can be computed on, and each
     * contribution's own version says which of them it wants (Slice B19); `taxableMonthly` is the
     * monthly-equivalent compensation WITHHOLDING TAX is computed on (scales with actual
     * earnings); `contribConfig` is the enrolled contributions; `overrides` are per-employee
     * statutory amounts that win over config.
     *
     * @param  array{basic: float, earnings: float}  $contribBases
     * @param  array<string, array<string, mixed>>  $contribConfig
     * @param  array{sss:?float, philhealth:?float, pagibig:?float}  $overrides
     * @param  array{index:int, count:int, deduction_timing:string, tax_timing:string}  $dist
     * @return array<string, mixed>
     */
    public function computePayslip(float $basePay, float $reimb, float $allowances, array $contribBases, float $taxableMonthly, array $contribConfig, array $overrides, array $dist, float $holidayPremium = 0.0, float $overtimePay = 0.0, float $nightDiffPay = 0.0, float $restDayPremium = 0.0, array $otherDeductions = ['total' => 0.0, 'lines' => []]): array
    {
        // Slice B19 — each contribution is charged on the figure ITS OWN version asks for, which
        // is why this is resolved per type rather than once. PhilHealth stays on basic salary by
        // law while SSS follows earnings, and that is the recommended setup, not an edge case.
        $sssBase = $this->contributionBaseFor('sss', $contribConfig, $contribBases);
        $phBase = $this->contributionBaseFor('philhealth', $contribConfig, $contribBases);
        $pgBase = $this->contributionBaseFor('pagibig', $contribConfig, $contribBases);

        // SSS/PhilHealth/Pag-IBIG are MONTHLY amounts (on a monthly base); the
        // timing setting decides which cutoff(s) in the month they land on.
        $sssM = $this->monthlyContribution('sss', $contribConfig, $overrides, $sssBase);
        $phM = $this->monthlyContribution('philhealth', $contribConfig, $overrides, $phBase);
        $pgM = $this->monthlyContribution('pagibig', $contribConfig, $overrides, $pgBase);

        $cf = $this->timingFactor($dist['deduction_timing'] ?? 'every', $dist['index'] ?? 0, $dist['count'] ?? 1);
        $sss = round($sssM * $cf, 2);
        $philhealth = round($phM * $cf, 2);
        $pagibig = round($pgM * $cf, 2);

        // Employer counterpart of each contribution (config `employer_rate`). Same
        // enrolment, base and timing as the employee side, but NOT deducted from pay — tracked
        // only for government remittance (see the Payroll report / run CSV).
        $erSss = round($this->monthlyEmployerContribution('sss', $contribConfig, $sssBase) * $cf, 2);
        $erPh = round($this->monthlyEmployerContribution('philhealth', $contribConfig, $phBase) * $cf, 2);
        $erPg = round($this->monthlyEmployerContribution('pagibig', $contribConfig, $pgBase) * $cf, 2);

        // Withholding tax on the taxable monthly compensation actually earned (minus the
        // employee's mandatory contributions), distributed by the tax-timing setting. What the
        // caller put into `taxableMonthly` (Slice 10): base pay + all four premium lines, plus
        // any allowance above the de-minimis ceiling. Reimbursements are never in it.
        $annualTaxable = max(0, ($taxableMonthly - ($sssM + $phM + $pgM)) * 12);
        $monthlyTax = $this->annualWithholdingTax($annualTaxable, $dist['cycle_end'] ?? null) / 12;
        $tf = $this->timingFactor($dist['tax_timing'] ?? 'every', $dist['index'] ?? 0, $dist['count'] ?? 1);
        $wht = round($monthlyTax * $tf, 2);
        $taxAnnualisation = 0.0;

        // Slice B17 — THIS PERIOD's taxable compensation, net of the employee's own contributions.
        // Stored per slip because the year's total is what the tax on 13th month is computed
        // against, and it must be a sum of what happened rather than a projection: twelve
        // annualised projections of a year are not a year.
        $periodsPerYear = max(1, (int) ($dist['periods_per_year'] ?? (($dist['count'] ?? 1) * 12)));
        $taxablePay = round($annualTaxable / $periodsPerYear, 2);

        // FIN-028 — YEAR-END TRUE-UP. On the cycle containing 31 December, stop projecting and
        // settle the year: tax actually due on what was actually earned, minus everything already
        // withheld. The caller supplies the prior totals; this is the only place that can apply
        // them, because it is the only place that knows this period's own taxable share.
        //
        // The result is deliberately allowed to be NEGATIVE. Over-withholding across the year is
        // the common case (a good month early on projects a higher bracket than the year lands in),
        // and the correction is a refund on this payslip. Nothing below clamps it: `min($total,
        // $gross)` leaves a negative total alone, and `max(0, $gross - $total)` then makes net pay
        // larger than gross, which is exactly what a refund is.
        if (isset($dist['year_end_true_up'])) {
            $priorTaxable = (float) ($dist['year_end_true_up']['taxable_prior'] ?? 0);
            $priorWithheld = (float) ($dist['year_end_true_up']['withheld_prior'] ?? 0);

            $annualDue = $this->annualWithholdingTax($priorTaxable + $taxablePay, $dist['cycle_end'] ?? null);
            $settled = round($annualDue - $priorWithheld, 2);

            // Recorded as the DIFFERENCE from what this period would otherwise have withheld, so
            // the payslip can name the adjustment rather than show an unexplained December figure.
            $taxAnnualisation = round($settled - $wht, 2);
            $wht = $settled;
        }

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

        // FIN-053 — and now say WHICH obligation went short. Capping the total while leaving the
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

        // FIN-005 — recover what earlier periods could not collect, from whatever room is left.
        //
        // ROOM IS WHAT REMAINS AFTER THIS PERIOD'S OWN OBLIGATIONS, and that order is deliberate.
        // Giving arrears priority would push the current period's statutory dues into shortfall,
        // creating fresh arrears to chase next period — the debt would rotate rather than clear.
        // The current period's contributions are due now; an arrear is already late, and recovering
        // it must not manufacture another one.
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
            // FIN-028 — how much of `withholding_tax` is the year-end correction. Signed:
            // positive collected, negative refunded. Zero on every period but the last.
            'tax_annualisation' => $taxAnnualisation,
            'taxable_pay' => $taxablePay,
            // Slice 11a — recurring benefit shares, with the breakdown so the payslip can
            // name them rather than show one opaque figure.
            'other_deductions' => $otherTotal,
            'other_deductions_breakdown' => $otherDeductions['lines'] ?? [],
            'employer_sss' => $erSss,
            'employer_philhealth' => $erPh,
            'employer_pagibig' => $erPg,
            // Slice B7 — which of the three came from a per-employee amount on the profile rather
            // than the government rates. An override replaces the EMPLOYEE side only, so the pair
            // above and below can belong to different salary brackets; without this the payslip
            // and the remittance file present that mismatch as if it were a proper contribution.
            // Recorded only for types the employee is enrolled in — an override on something they
            // are not enrolled in deducts nothing and is not in force.
            'contribution_overrides' => array_values(array_filter(
                ['sss', 'philhealth', 'pagibig'],
                fn ($t) => isset($contribConfig[$t]) && ($overrides[$t] ?? null) !== null,
            )) ?: null,
            // Slice B19 — what each contribution was actually charged on, snapshot because it can
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
            // FIN-053 — the same figure split by obligation, so the arrears can be named. Null
            // when nothing went short, which is the overwhelming majority of payslips.
            'deduction_carryover_breakdown' => $uncollected ?: null,
            // FIN-005 — what this payslip recovered from EARLIER periods' shortfalls. Subtracted
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
     * Outstanding deduction arrears per employee, per obligation (FIN-005).
     *
     * DERIVED, NOT STORED: owed minus recovered, both read back off the payslips. A running balance
     * column decremented per run is the failure mode Slices 5, 12 and 11b each had to unpick, and
     * `EmployeeLoan` already demonstrates the alternative — the ledger IS the balance.
     *
     * `$excludeRunId` keeps a re-run from settling against its own previous attempt: without it, a
     * recompute would read the arrears its own prior pass had already recovered, find them gone,
     * and quietly stop recovering them.
     *
     * Only `regular` runs contribute. 13th-month and adjustment runs both write a zero carryover by
     * construction, so including them would add nothing but a wider query.
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
        //
        // Cast to float explicitly: the amounts arrive from `json_decode`, which yields an INT for
        // a whole-peso value, and callers comparing against a money figure should not have to care
        // which. The return type says float, so it returns float.
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
     * Split a capped deduction total across the obligations it is made of (FIN-053).
     *
     * When gross cannot cover everything owed, something goes uncollected — the question is WHAT,
     * and until now the payslip had no answer. It capped the total and left every component at its
     * computed value, so the parts summed to more than the whole and the remittance report summed
     * the parts.
     *
     * PRIORITY ORDER: statutory contributions → withholding tax → benefit shares. The statutory
     * three come first because they are the employee's own entitlement record — a missed SSS month
     * is a gap in their pension history, not just an unpaid bill — and benefit shares come last
     * because they are the only voluntary item in the list. Within the statutory three the order is
     * SSS, PhilHealth, Pag-IBIG, which is arbitrary but fixed: they are all mandatory and nothing
     * distinguishes them, so what matters is that the answer is deterministic and stated, rather
     * than falling out of whatever order the array happened to be built in.
     *
     * Loans are NOT here. They are collected after the payslip against remaining net
     * (`LoanService::collectForPeriod()`), which already takes min(instalment, balance, net) and
     * carries the rest on the loan ledger — a shortfall there is already tracked, and is the one
     * case the original "leave it on the balance" convention actually handled.
     *
     * A NEGATIVE COMPONENT IS NOT A DEDUCTION. `withholding_tax` goes negative on the December
     * true-up, where the year over-withheld and the payslip carries a refund (FIN-028). A refund
     * ADDS to what is available, so negatives are honoured in full and folded into the pool rather
     * than competing for it. Allocating them like deductions would zero the refund to "afford" a
     * contribution — taking money the employee is owed to pay a bill they also owe, silently.
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
     * The monthly salary figure one contribution is charged on (Slice B19).
     *
     * The version in force names the basis; `basic` is the answer for anything that does not,
     * which is every version recorded before this slice and every type the employee is not
     * enrolled in. An unrecognised value falls back to `basic` for the same reason
     * `AllowanceService` defaults an unknown tax treatment to de-minimis: guessing the
     * larger base for a value we do not understand would over-deduct from real people.
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

        return ContributionCalculator::employer($config[$type], $base);
    }

    /**
     * One contribution from its config — the employee side.
     *
     * Slice B7 — the arithmetic moved to `ContributionCalculator` so the employee form can show
     * what the rates WOULD charge next to a per-employee override without a second implementation
     * of it. This wrapper stays because payroll reads better for it.
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
            'auto_after_threshold' => $this->autoOvertimeMinutes($employee, $start, $end, $policy),
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
    /**
     * Auto-mode overtime, capped, with the over-cap minutes payable only on an approval (FIN-039).
     *
     * The two caps bound what pays with nobody looking; they are deliberately NOT a pay cut. A day
     * trimmed by a cap keeps its full figure available, and an approved overtime request covering that
     * date releases it. So the mode reads: up to the cap, being clocked in is enough; past the cap,
     * somebody has to say yes. That is what makes a forgotten clock-out cost a review-queue item
     * instead of five hours at 125%.
     *
     * The approval is treated as covering the DAY's total overtime, not just the excess, which is how
     * HR actually files it — they see "7.5 hours worked past shift end" and approve that. Hence
     * `$approved − $paid`: only the part the cap didn't already pay is added.
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
        );

        $map = AttendanceService::applyAutoOvertimePeriodCap(
            $map,
            (int) $policy->auto_overtime_period_cap_minutes,
        );

        $withExcess = array_filter($map, fn (array $e) => ($e['excess_minutes'] ?? 0) > 0);
        if ($withExcess === []) {
            return $map; // nothing was held back, so nothing needs releasing
        }

        // Only now is the approved-request query worth running.
        $approved = $this->approvedOvertimeMinutes($employee, $start, $end);

        foreach (array_keys($withExcess) as $date) {
            $entry = $map[$date];
            $release = min(
                (int) $entry['excess_minutes'],
                max(0, (int) ($approved[$date]['minutes'] ?? 0) - (int) $entry['minutes']),
            );

            if ($release <= 0) {
                continue;
            }

            // Extend the paid window rather than adding a bare total, so night differential covers
            // exactly the minutes that are now being paid.
            $map[$date]['minutes'] = (int) $entry['minutes'] + $release;
            $map[$date]['night'] = AttendanceService::nightOverlapMinutes(
                (int) $entry['window_start'],
                (int) $entry['window_start'] + $map[$date]['minutes'],
            );
            $map[$date]['excess_minutes'] = (int) $entry['excess_minutes'] - $release;
        }

        return $map;
    }

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
    private function annualWithholdingTax(float $t, ?string $asOf = null): float
    {
        // FIN-027 — the table now comes from `hr_tax_versions`, resolved as of the cycle end, the
        // same way salaries, statutory rates, allowances and the attendance policy already are.
        // The brackets used to be a `match (true)` literal here: correct, but undateable, so a
        // re-run of an old period priced it at today's table and a bracket change needed a deploy.
        return $this->tax->annualTax($t, $asOf);
    }

    /**
     * Pay the year's 13th month — Slice B17.
     *
     * WHAT THIS CLOSES. 13th month was a report and nothing more: no payslip carried it, so no tax
     * was ever withheld on it. It is also the largest item in the ₱90,000 exemption it shares with
     * the excess de minimis (B16), so for anyone whose 13th month clears that figure the company
     * was under-withholding and would have found out at year-end.
     *
     * THE BUCKET IS READ BACK, NOT RECOMPUTED. Both halves come from the payslips actually issued
     * during the year — `thirteenth_month` from the basic pay they paid, `other_benefits` from
     * what each period contributed. Deriving either from today's catalogue would let a mid-year
     * allowance change rewrite the year a December run is measured against.
     *
     * THE TAX IS MARGINAL, which is what annualisation means: the exemption's excess is added on
     * top of the compensation the employee has actually been taxed on for the year, and the tax is
     * the difference. Taxing the excess in isolation would charge it from the bottom bracket up
     * and under-withhold for everybody.
     *
     * NOT A STATUTORY-CONTRIBUTION EVENT. SSS, PhilHealth and Pag-IBIG are charged on monthly
     * compensation and are not levied again on 13th month, so this run carries none.
     */
    /**
     * What a 13th-month run WOULD pay, without writing anything — Slice B17.
     *
     * The dialog that offers the button shows a *projection* built from a different query, and the
     * two differ whenever the year is partial. Confirming a payroll run against a figure it is not
     * going to pay is the kind of small dishonesty that costs trust the first time someone checks.
     * Same shape as B8's schedule import: read it, show it, save nothing until HR says yes.
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

    /**
     * The ONE definition of what a year's 13th-month pay is measured from (FIN-013).
     *
     * There were three, and they disagreed. `computeThirteenthMonth()` filtered
     * `type = 'regular'` but not status, so a run still mid-recompute (`processing`) could
     * contribute half-written payslips to the figure actually PAID. `thirteenthMonth()` — the
     * accrual report HR reviews — filtered `status = 'completed'` but not type, so a previous
     * 13th-month run counted toward the next one. `ytd()` did the same. The number on the report,
     * the number in the preview dialog and the number paid could all differ, and no two of them
     * were wrong in the same direction.
     *
     * The rule, stated once: **regular runs only** (13th month is not itself basic salary, and
     * including it compounds on every re-run), **completed runs only** (a `processing` run is
     * half-written by definition). Philippine law measures the entitlement on total BASIC salary
     * earned in the year, which is `hr_payslips.base_salary` — attendance-adjusted, and excluding
     * allowances, premiums and reimbursements.
     *
     * Returns a query, not results, so each caller can select what it needs — a per-employee sum,
     * a grouped report, or the full set — without a second opinion about which rows qualify.
     */
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
        // FIN-027 — 13th month is paid at year end, so it is taxed on the table in force then.
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
            // FIN-025 — the ₱90,000 other-benefits cap as it stood at that year end.
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
                // FIN-036 — a 13th-month run is paid on a date too, and its payslip says so.
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

            // FIN-012 — this loop used to be a bare updateOrCreate, missing BOTH guards that
            // run() has. Re-running after part of the 13th month had been paid rewrote amounts
            // that were already declared disbursed (observed: a paid ₱27,500 silently became
            // ₱30,000), and an employee who no longer qualified kept a stale payslip that still
            // counted toward YTD, the remittance report and this run's own totals.
            //
            // Mirrors run(): skip disbursed slips but roll their existing figures into the
            // summary, then force-delete the computed slips of anyone no longer in the set.
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
                        'holiday_premium' => 0, 'overtime_pay' => 0, 'night_diff_pay' => 0,
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

            // FIN-012 — drop the computed slips of anyone who fell out of the set on this re-run
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
     * Pay an off-cycle correction (FIN-010).
     *
     * WHAT IT DOES NOT DO IS THE POINT. It does not read attendance, does not prorate, does not
     * resolve a salary, and takes no statutory contributions — those are monthly and were already
     * taken on the period being corrected. It pays amounts a person has stated, with a reason, and
     * withholds tax on them. Anything more would make it a second pay engine, and two engines that
     * both claim to compute pay is precisely the divergence this audit is about.
     *
     * TAX IS MARGINAL, not a fresh annualisation. The employee has already been withheld on this
     * year's pay; a correction adds to that total, so the tax due is the difference between the
     * annual tax on (what has been taxed so far + this amount) and the annual tax on what has been
     * taxed so far. Exactly the method `computeThirteenthMonth()` uses for the excess over ₱90,000,
     * and for the same reason: taxing the amount in isolation would apply the lowest bracket to
     * money that actually sits on top of a year's earnings.
     *
     * RE-RUNNABLE, like every other run here: recomputing replaces this batch's lines. A disbursed
     * payslip is never touched (FIN-012's rule, applied from the start rather than retrofitted).
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

                // What this employee has already been taxed on this year, from the regular runs —
                // the same basis the 13th-month calculation uses, so the two agree.
                $alreadyTaxed = (float) $this->thirteenthMonthBasisFor($year)
                    ->where('hr_payslips.employee_id', $employeeId)
                    ->sum('hr_payslips.taxable_pay');

                $tax = round(
                    $this->annualWithholdingTax($alreadyTaxed + $amount, $taxAsOf)
                        - $this->annualWithholdingTax($alreadyTaxed, $taxAsOf),
                    2,
                );

                Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employeeId],
                    [
                        'base_salary' => 0, 'period_base' => 0, 'absence_deduction' => 0,
                        'reimbursements' => 0, 'allowances' => 0, 'allowances_breakdown' => [],
                        'holiday_premium' => 0, 'overtime_pay' => 0, 'night_diff_pay' => 0,
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
     * Pay a leaver everything terminal they are owed (FIN-014).
     *
     * FOUR THINGS, and the regular run does none of them. It already prorates the leaver's final
     * cycle (Slice 4, `withTrashed()`), so this deliberately does NOT touch basic salary — it pays:
     *
     *   1. unused leave converted to cash at the daily rate,
     *   2. separation pay as HR entered it (see the migration for why it is entered, not computed),
     *   3. the outstanding loan balance, settled against net — loans otherwise simply stopped being
     *      collected when someone left, because `collectForPeriod()` only runs for a pay group they
     *      are no longer in,
     *   4. tax annualised across the year actually worked, not projected as if the year continued.
     *
     * THE TAX TREATMENT IS THE PART WORTH READING.
     *
     * Monetised unused vacation leave is exempt up to **12 days** — raised from 10 by BIR RR 29-2025,
     * the same regulation that moved the rice and uniform ceilings. Anything above 12 days joins the
     * ₱90,000 "13th month and other benefits" bucket rather than becoming taxable compensation
     * directly, which is the chain B16 built and FIN-024 documented.
     *
     * Separation pay is exempt when the cause is beyond the employee's control (NIRC §32(B)(6)(b));
     * HR marks the exception with `separation_pay_taxable`, because the system holds no fact that
     * distinguishes a retrenchment from a negotiated exit.
     *
     * Then the whole year is trued up exactly as December does (FIN-028): annual tax due on what was
     * actually earned, minus what was actually withheld. For a mid-year leaver that is almost always a
     * REFUND — they were withheld all year against a projected full-year income they never reached.
     * Getting this wrong in the other direction is the common real-world payroll complaint.
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
            //
            // A refund is cash, so it also ENLARGES what a loan can be settled from.
            $availableNet = round(max(0, $gross - $tax), 2);
            $loanSettlement = $this->loans->settleOnSeparation($employee, $run, $availableNet);

            $totalDeductions = round($tax + $loanSettlement, 2);

            $payslip = Payslip::updateOrCreate(
                ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                [
                    'base_salary' => 0, 'period_base' => 0, 'absence_deduction' => 0,
                    'reimbursements' => 0, 'allowances' => 0, 'allowances_breakdown' => [],
                    'holiday_premium' => 0, 'overtime_pay' => 0, 'night_diff_pay' => 0,
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

    /**
     * Monetised unused vacation leave is exempt up to this many days.
     *
     * **12, not 10** — raised by BIR RR 29-2025 (issued 22 Dec 2025, effective 6 Jan 2026), the same
     * regulation that moved rice to ₱2,500/month and uniform to ₱8,000/year. The older figure is
     * still what most references say, which is exactly why it is a named constant with its source.
     */
    private const EXEMPT_LEAVE_CONVERSION_DAYS = 12;

    /**
     * Unused leave days this employee has left, in whichever mode the company runs.
     *
     * Only PAID types count: converting unpaid leave to cash would pay for time that was never
     * going to be paid. Shared mode holds a single pool, per-type mode holds a row per type.
     */
    /**
     * What one employee's unused paid leave would cost if it were paid out today (MF-11).
     *
     * UNUSED PAID LEAVE IS A LIABILITY, and it was nowhere on the books. It becomes cash the moment
     * somebody leaves — `runFinalPay()` converts it at the daily rate — and for the five-day Service
     * Incentive Leave the Labour Code requires that conversion outright (Art. 95). So the company owes
     * it whether or not anyone has left yet, and a balance sheet that omits it understates payroll
     * obligations by the whole accrued balance.
     *
     * IT DELIBERATELY REUSES `unusedLeaveDaysFor()` AND `dailyRateFor()` — the exact functions final pay
     * uses — rather than recomputing the same idea a second time. A liability report that disagreed with
     * the payslip it is predicting would be worse than no report: accounting would accrue one figure
     * and pay another, and nobody could tell which was wrong. Any change to how final pay values leave
     * moves this report with it, by construction.
     *
     * Priced at TODAY's rate (or `$asOf`), not the rate at separation, because that is unknowable — a
     * raise between now and someone's eventual last day changes what this costs. It is an accrual
     * estimate, and the report says so.
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
    /**
     * Refuse when the same person is about to do both halves of a two-party action (FIN-020).
     *
     * Silent when the setting is off, when there is no recorded first actor (a run from before the
     * provenance columns existed), or when either side is unknown — a control that guessed would block
     * legitimate work on old data, and the point is separation of duties, not a puzzle about nulls.
     *
     * `super_admin` IS NOT EXEMPT, deliberately. An exemption for the most privileged account is an
     * exemption for exactly the account whose compromise the control exists to contain.
     */
    private function assertDifferentActor(?int $firstActorId, ?int $secondActorId, string $message, string $field): void
    {
        if (! PayrollSetting::current()->require_maker_checker) {
            return;
        }

        if ($firstActorId === null || $secondActorId === null) {
            return;
        }

        if ($firstActorId !== $secondActorId) {
            return;
        }

        throw ValidationException::withMessages([$field => [$message]]);
    }

    public function unlock(PayrollRun $run, User $user): PayrollRun
    {
        if (! $run->isLocked()) {
            throw ValidationException::withMessages([
                'locked_at' => ['This payroll run is not locked.'],
            ]);
        }

        // FIN-020 — the person who sealed the period cannot be the one who reopens it. Unlocking is
        // what makes a closed cycle recomputable, so it is the single most consequential undo here.
        $this->assertDifferentActor(
            $run->locked_by,
            $user->id,
            'You locked this run, so you cannot unlock it yourself. Someone else has to reopen it.',
            'locked_at',
        );

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
    public function markDone(Payslip $payslip, ?User $actor = null, ?string $idempotencyKey = null): Payslip
    {
        // FIN-033 — the one act that asserts money left the company, now with preconditions.
        //
        // IDEMPOTENCY FIRST, before the refusals. A repeat carrying the same key is a duplicate
        // request — a double-click, a retried POST, a re-imported reconciliation file — and is
        // answered as success without acting twice. Erroring on a double-click trains people to
        // ignore the error, which is how the genuine refusal below stops being read.
        if ($payslip->status === 'disbursed') {
            if ($idempotencyKey !== null && $payslip->disbursement_key === $idempotencyKey) {
                return $payslip->load(['employee.user', 'employee.department']);
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

        // FIN-020 — MAKER–CHECKER. The person who ran the cycle cannot be the person who asserts the
        // money left. That is the control finance auditors look for first, and without it one account
        // could raise a salary, run payroll, pay it and seal the period unaided.
        //
        // Checked against `run_by`, which was already recorded — the provenance existed all along and
        // nothing acted on it. Off by default; see the migration for why that trade was made.
        $this->assertDifferentActor(
            $run?->run_by,
            $actor?->id ?? Auth::id(),
            'This cycle was run by the same person. Someone else has to mark it paid — '
                . 'that separation is what the maker–checker setting requires.',
            'status',
        );

        return DB::transaction(function () use ($payslip, $actor, $idempotencyKey) {
            $payslip->update([
                'status' => 'disbursed',
                // Provenance for the one operation an auditor asks about first. `updated_at` was
                // the only record of "when", and any later write overwrote it.
                'disbursed_at' => now(),
                'disbursed_by' => $actor?->id ?? Auth::id(),
                'disbursement_key' => $idempotencyKey,
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

    /**
     * Undo the above — the payslip goes back to `computed` and the claims it settled go
     * back to `scheduled`, i.e. owed on this run again. Use when a disbursement was
     * recorded in error.
     *
     * A locked run is refused: locking requires every payslip disbursed, so un-disbursing
     * one would leave the run sealed in a state it could never have been locked in. Unlock
     * it first. Reversing also re-opens the payslip to recompute, which is the point —
     * whatever was wrong upstream can now be fixed and the run re-run.
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
                // FIN-033 — clear the provenance. Leaving `disbursement_key` set would make the
                // idempotency check treat the NEXT genuine disbursement from the same client as a
                // duplicate and silently no-op it, and a stale `disbursed_by` would credit someone
                // with a payment that was reversed.
                'disbursed_at' => null,
                'disbursed_by' => null,
                'disbursement_key' => null,
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

            // FIN-003(a) — and put back the loan instalments this payslip collected. Without
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
    public function run(string $cycleStart, string $cycleEnd, string $frequency, User $runner): PayrollRun
    {
        $startedAt = microtime(true);

        // FIN-035 — refuse before anything is written if an active statutory contribution has no
        // rate version in force for this cycle. Outside the transaction on purpose: this is a
        // precondition on the request, not a step in the run, and HR should see the reason rather
        // than a rolled-back run that appears to have done nothing.
        $this->contributions->assertRatesInForce($cycleEnd);

        $run = DB::transaction(function () use ($cycleStart, $cycleEnd, $frequency, $runner) {
            // FIN-025 — the settings IN FORCE for this period, not whatever they are today.
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
                // Slice 10 — the ceiling above which allowances become taxable (null = none).
                'other_benefits_annual_cap' => $setting->other_benefits_annual_cap,
                // Slice B3a — resolves the statutory rates in force for this period.
                'cycle_end' => $cycleEnd,
                // Slice B2 — decides whether a dated plan enrolment covers this period at all.
                'cycle_start' => $cycleStart,
                // Slice B17 — so a period can record its own share of the year's taxable pay.
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
                        // FIN-001 — stated HERE as well as in the column default, deliberately,
                        // for the reason PayrollSetting::current() documents: `create()` returns a
                        // model built from the attributes it INSERTED, so a DB-side default lands
                        // on the row while the in-memory instance still reads null. The overlap
                        // guard below filters on `$run->type`, and a null there would match no run
                        // at all — silently disabling the double-pay guard on every first run of a
                        // cycle, which is worse than the bug it was added to fix.
                        'type' => 'regular',
                        'status' => 'processing',
                        // FIN-036 — stamped once, here. Derived from the delay in force NOW, then
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
            $employees = $this->payGroupEmployees($frequency, $cycleStart, $cycleEnd, $setting->frequency);

            // Holidays in this cycle (date => type), fetched once for the whole run.
            $holidayMap = $this->holidays->typedHolidaysBetween(Carbon::parse($cycleStart), Carbon::parse($cycleEnd));

            // The attendance policy drives the daily-rate basis (Slice A) — fetch once, reuse.
            //
            // asOf($cycleEnd), not get(): the rules as they stood at the END OF THIS CYCLE, the
            // same resolution date salary (13c), the statutory rates (B3a) and allowances (B11)
            // already use. Before this, re-running any cycle that was not locked or disbursed
            // repriced it with today's divisor and today's deduction modes.
            $policy = $this->attendancePolicy->asOf($cycleEnd);

            // Slice 13c — the salary each employee was actually on during this period.
            $salaryAsOf = $this->salariesAsOf($employees, $cycleEnd);

            // FIN-005 — what earlier periods failed to collect from each employee, so this period
            // can recover it from whatever room is left after its own obligations. Excludes this
            // run so a re-run does not settle against its own previous attempt.
            $arrears = $this->deductionArrearsFor($employees, $run->id);

            // FIN-028 — is this the cycle that closes the tax year?
            //
            // Tested by CONTAINMENT of 31 December rather than by `cycle_end === '{year}-12-31'`,
            // because only monthly and semi-monthly land exactly on it. A weekly or bi-weekly
            // group's final cutoff straddles the year boundary, and those employees need the
            // true-up just as much.
            $yearEnd = Carbon::parse($cycleEnd)->year . '-12-31';
            $isFinalCycleOfYear = $cycleStart <= $yearEnd && $cycleEnd >= $yearEnd;

            // What each employee has already been taxed on, and already had withheld, this year —
            // from the REGULAR completed runs only, which is the same basis 13th month uses. One
            // query for the whole run rather than two per employee.
            $yearToDateTax = [];
            if ($isFinalCycleOfYear) {
                $yearToDateTax = $this->thirteenthMonthBasisFor((int) Carbon::parse($cycleEnd)->year)
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

            // Slice 15 — who already has a payslip in an OVERLAPPING run, resolved in ONE query
            // instead of an exists() per employee. Same question, same answer, 1/N the round trips.
            //
            // FIN-001 — THE GUARD IS SCOPED TO RUNS OF THE SAME TYPE, and must stay that way.
            //
            // It used to match on dates alone. A 13th-month run is created at {year}-12-01 to
            // {year}-12-31, which overlaps every December regular cycle exactly — so paying 13th
            // month made every employee read as "already paid for December". Two outcomes, both
            // observed in PayrollThirteenthMonthOverlapTest: December had not run yet and nobody
            // was paid at all, or December had already run and the re-run force-deleted its
            // payslips, because `$keep` emptied and an empty `whereNotIn` compiles to `1 = 1`.
            //
            // The question this guard is actually asking is "has this employee already been paid
            // THIS KIND of pay for this period?" — the case it exists for is an employee whose
            // pay_frequency changed after being run in another group, i.e. two REGULAR runs
            // covering the same days. 13th month, and every future run type (adjustment,
            // off-cycle, final pay — FIN-010), is different money for the same days and must not
            // block the regular cycle, or be blocked by it.
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

                // Reimbursements folded into this period's pay, PLUS any already scheduled by
                // THIS run (so a re-run keeps them folded). Auto-flipped to `scheduled` + linked
                // to the run below.
                //
                // FIN-023 — THE WINDOW IS `<= cycleEnd`, NOT `BETWEEN cycleStart AND cycleEnd`.
                //
                // A run used to fold in only claims whose expense_date fell inside its own cycle,
                // and no later run ever looked backwards. So a July expense approved in September
                // could only be paid by re-running July — which is refused once that run is locked
                // or its payslip disbursed. The claim sat `approved` forever, and the only escape
                // was marking it paid by hand outside payroll, which records no payment anywhere.
                //
                // An unattached approved claim is now picked up by the next run regardless of how
                // old it is. `whereNull('payroll_run_id')` is what makes that safe: a claim
                // already scheduled onto another run is never stolen from it.
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
                // Management staff are on a FIXED salary — the unpaidDaysDetailFor()/holidayPremiumFor()
                // helpers themselves return 0 for management, so pay is never prorated by attendance.
                // $policy is passed EXPLICITLY. It always accepted one (the preview/dry-run
                // override, step 6b) and this call never supplied it, so the late and undertime
                // deductions re-read the live singleton instead of the policy resolved above.
                // That was invisible while the two were always the same object; now that the
                // policy is dated, omitting it would price every deduction at today's rules and
                // silently defeat asOf() for the one part of the payslip it matters most to.
                $unpaidDetail = $this->attendance->unpaidDaysDetailFor($employee, $cycleStart, $cycleEnd, $policy);

                // Premium for working on a (non-working) holiday in this cycle.
                $holidayPremium = $this->holidayPremiumFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy);
                // Rest days worked feed BOTH the rest-day premium and the overtime cap below.
                $restByDate = $this->attendance->restDayWorkedMinutesFor($employee, $cycleStart, $cycleEnd, $holidayMap);
                $restDay = $this->restDayPremiumFor($employee, $periodsPerYear, $workingDays, $restByDate, $policy);
                // One overtime source, shared by the OT line and the night differential.
                $otByDate = $this->overtimeMinutesByDate($employee, $cycleStart, $cycleEnd, $policy, $restByDate);
                $overtime = $this->overtimePayFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy, $otByDate);
                $nightDiff = $this->nightDiffPayFor($employee, $cycleStart, $cycleEnd, $periodsPerYear, $workingDays, $holidayMap, $policy, $otByDate);

                // FIN-028 — on the cycle that closes the tax year, hand this employee's prior
                // totals down so computePayslip settles the year instead of projecting it again.
                // Absent on every other cycle, which leaves the existing behaviour untouched.
                $employeeDist = $isFinalCycleOfYear
                    ? [...$dist, 'year_end_true_up' => $yearToDateTax[$employee->id] ?? ['taxable_prior' => 0.0, 'withheld_prior' => 0.0]]
                    : $dist;

                // FIN-005 — this employee's outstanding shortfall, if any. Absent for almost
                // everyone, which keeps the ordinary payslip untouched.
                if (isset($arrears[$employee->id])) {
                    $employeeDist['arrears'] = $arrears[$employee->id];
                }

                $slip = $this->payslipFor($employee, $reimbTotal, $unpaidDetail->unpaidDays, $periodsPerYear, $workingDays, $employeeDist, $holidayPremium, $unpaidDetail, $policy, $overtime['pay'], $overtime['minutes'], $nightDiff['pay'], $nightDiff['minutes'], $restDay['pay'], $restDay['minutes']);

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
                    // FIN-032 — the diminishing-balance rate is per year; this splits it per payday.
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
            //
            // FIN-001 — AN EMPTY `$keep` DELETES EVERY COMPUTED PAYSLIP IN THIS RUN, and that is
            // correct: `whereNotIn` over an empty set compiles to `1 = 1`, and an empty `$keep`
            // means every employee either left the pay group or is genuinely paid by an
            // overlapping run of the SAME type. Both deserve the cleanup.
            //
            // It is called out because this is the blast radius of anything that wrongly inflates
            // `$paidElsewhere`. That is exactly what happened when the guard above matched on
            // dates alone: a 13th-month run marked everyone as paid, `$keep` emptied, and a
            // December re-run destroyed the payslips December had already produced. The guard is
            // the control; this line is what it protects. No extra filter is needed here — these
            // three cleanups are scoped to `$run` and cannot reach another run's rows.
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
                'policy_snapshot' => $this->policySnapshot($setting, $cycleEnd),
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
            // FIN-013 — the TOTALS above intentionally span every completed run (13th-month pay
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

    /**
     * Render one payslip to a DomPDF document — the same document the UI shows.
     *
     * Fed by PayslipResource so the PDF and the screen can never disagree about what a payslip
     * contains. The one deliberate difference is the employer share, which the view drops
     * entirely: a downloaded payslip is a document that gets forwarded, so HR's copy and the
     * employee's are identical files.
     *
     * Amounts are bare numbers with a single "PHP" on the net line — DomPDF's bundled fonts
     * have no ₱ glyph, so a peso sign would render as a blank box.
     */
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
        $payslip->loadMissing(['payrollRun.runner', 'employee.user', 'employee.department']);

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
        $run->loadMissing(['payslips.employee.user', 'payslips.employee.department']);

        $sum = ['base' => 0.0, 'allow' => 0.0, 'holiday' => 0.0, 'ot' => 0.0, 'nd' => 0.0, 'rd' => 0.0, 'reimb' => 0.0, 'sss' => 0.0, 'ph' => 0.0, 'pg' => 0.0, 'wht' => 0.0, 'other' => 0.0, 'er_sss' => 0.0, 'er_ph' => 0.0, 'er_pg' => 0.0];

        // FIN-019 — every row goes through CsvWriter, which neutralises leading =, +, -, @, tab
        // and CR. Employee and department names are free text a person controls, and this file is
        // opened in Excel on a finance workstation.
        $rows = [];
        $rows[] = [
            'Employee', 'Department', 'Base', 'Allowances', 'Holiday Premium', 'Overtime Pay', 'Night Diff', 'Rest Day', 'Reimbursements', 'Gross',
            'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Other Deductions', 'Total Deductions', 'Net Pay',
            // Slice B7 — this file doubles as the remittance summary, and a per-employee override
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

        // No BOM: this export is machine-read as often as it is opened by a person, and adding
        // one now would change every existing consumer's first column header.
        $csv = CsvWriter::render($rows, bom: false);

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
        // FIN-013 — the same basis the run itself uses, so the accrual report and the payment
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
