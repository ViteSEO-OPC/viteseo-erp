<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * FIN-053 — when pay cannot cover the deductions, the payslip has to say WHICH one went short.
 *
 * THE DEFECT. `total_deductions` was capped at gross, but `sss`, `philhealth`, `pagibig`,
 * `withholding_tax` and `other_deductions` kept their full computed values. The parts summed to
 * more than the whole. One payslip at a time this was hidden — the document credited the aggregate
 * shortfall back as an `uncollected` line and resolved — but the government remittance report sums
 * those columns straight out of the table, so it reported more withheld from SSS, PhilHealth,
 * Pag-IBIG and BIR than was ever taken off anyone's pay. A filing built on it over-reports employee
 * contributions the company cannot evidence from its own payslips.
 *
 * WHAT IS PINNED HERE. The invariant (parts == whole), the priority order that decides who goes
 * short, that the ordinary full-pay payslip is untouched, that a year-end tax refund is not
 * cannibalised to pay a contribution, and that the remittance report now agrees with the payslips
 * it is built from. That last one is the finding: the rest is how it is achieved.
 */
class DeductionAllocationTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Real brackets — a shortfall split across zeroed contributions proves nothing.
        $this->seed(ContributionSettingSeeder::class);

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function workedEveryDay(): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);

        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }

            Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    /** A benefit share large enough that gross cannot cover the deductions. */
    private function enrolInHugeDeduction(float $share): void
    {
        $program = BenefitProgram::create([
            'name' => 'Emergency Loan', 'category' => 'other', 'kind' => 'deduction',
            'deduction_cadence' => 'per_cutoff',
        ]);
        $this->employee->benefitPrograms()->attach($program->id, ['employee_share' => $share]);
    }

    private function runPayroll(): Payslip
    {
        app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );

        return Payslip::where('employee_id', $this->employee->id)->firstOrFail();
    }

    /**
     * A payslip whose gross cannot even cover the STATUTORY deductions.
     *
     * Harder to produce than it looks. Contributions are charged on the full monthly salary while
     * gross scales with days worked — but they are also split across the month's cutoffs, so one
     * day at ₱20,000/month still grosses ₱1,000 against ~₱850 of per-cutoff contributions and
     * covers them comfortably. What does not cover them is a LOW salary: the PhilHealth ₱10,000
     * floor and the lowest SSS bracket hold the contribution up while the daily rate falls with
     * the salary. ₱5,000/month, one day worked → ₱250 gross against ₱300 of contributions.
     */
    private function oneDayOnALowSalary(): Payslip
    {
        $this->employee->update(['base_salary' => 5000]);

        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-03-02',
            'time_in' => Carbon::parse('2026-03-02 08:00', $tz)->utc(),
            'time_out' => Carbon::parse('2026-03-02 17:00', $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);

        return $this->runPayroll();
    }

    private function componentSum(Payslip $slip): float
    {
        return round(
            (float) $slip->sss + (float) $slip->philhealth + (float) $slip->pagibig
                + (float) $slip->withholding_tax + (float) $slip->other_deductions,
            2,
        );
    }

    // --- the invariant ---------------------------------------------------------------------

    /** THE finding. Before the fix these differed by exactly the carryover. */
    public function test_the_deduction_components_sum_to_the_capped_total(): void
    {
        $this->workedEveryDay();
        $this->enrolInHugeDeduction(50000);
        $slip = $this->runPayroll();

        $this->assertGreaterThan(0.0, (float) $slip->deduction_carryover, 'the fixture must actually go short');

        $this->assertEqualsWithDelta(
            (float) $slip->total_deductions,
            $this->componentSum($slip),
            0.01,
            'the parts of total_deductions must sum to it',
        );
    }

    /** A payslip that covers its deductions must be byte-for-byte what it always was. */
    public function test_a_payslip_that_covers_its_deductions_is_untouched(): void
    {
        $this->workedEveryDay();
        $slip = $this->runPayroll();

        $this->assertEqualsWithDelta(0.0, (float) $slip->deduction_carryover, 0.01);
        $this->assertNull($slip->deduction_carryover_breakdown, 'nothing to itemise when nothing went short');

        $this->assertGreaterThan(0.0, (float) $slip->sss, 'the assertion below must have teeth');
        $this->assertEqualsWithDelta(
            (float) $slip->total_deductions,
            $this->componentSum($slip),
            0.01,
        );
    }

    // --- who goes short --------------------------------------------------------------------

    /**
     * Priority: statutory → tax → benefit shares. The employee's SSS/PhilHealth/Pag-IBIG record is
     * their own entitlement history, so it is protected ahead of a voluntary company deduction.
     */
    public function test_statutory_contributions_are_collected_before_benefit_shares(): void
    {
        $this->workedEveryDay();
        $this->enrolInHugeDeduction(50000);
        $slip = $this->runPayroll();

        $breakdown = $slip->deduction_carryover_breakdown;

        $this->assertArrayHasKey('other_deductions', $breakdown, 'the voluntary share absorbs the shortfall');
        $this->assertArrayNotHasKey('sss', $breakdown, 'SSS is collected ahead of a benefit share');
        $this->assertArrayNotHasKey('philhealth', $breakdown);
        $this->assertArrayNotHasKey('pagibig', $breakdown);

        // Collected in full, exactly as on a payslip that could afford everything.
        $this->assertGreaterThan(0.0, (float) $slip->sss);
        $this->assertGreaterThan(0.0, (float) $slip->philhealth);
    }

    /** The itemised shortfall has to account for the aggregate, or the arrears cannot be trusted. */
    public function test_the_itemised_shortfall_sums_to_the_carryover(): void
    {
        $this->workedEveryDay();
        $this->enrolInHugeDeduction(50000);
        $slip = $this->runPayroll();

        $this->assertEqualsWithDelta(
            (float) $slip->deduction_carryover,
            round(array_sum($slip->deduction_carryover_breakdown), 2),
            0.01,
        );
    }

    /**
     * When even the statutory three cannot be covered, the shortfall reaches them in order — and
     * the invariant still holds. This is the case the priority order actually exists to decide.
     */
    public function test_a_shortfall_deep_enough_to_reach_the_statutory_side_still_reconciles(): void
    {
        $slip = $this->oneDayOnALowSalary();

        $this->assertGreaterThan(0.0, (float) $slip->deduction_carryover);
        $this->assertEqualsWithDelta(
            (float) $slip->total_deductions,
            $this->componentSum($slip),
            0.01,
        );

        // The order is SSS → PhilHealth → Pag-IBIG, so the last of the three is what goes short.
        // Pinned concretely rather than by "something went short": the whole point of a fixed
        // priority is that the answer is the same every time, and a vaguer assertion would pass
        // just as happily if the order silently reversed.
        $this->assertSame(['pagibig'], array_keys($slip->deduction_carryover_breakdown));
        $this->assertGreaterThan(0.0, (float) $slip->sss, 'SSS is first and must be collected whole');
        $this->assertGreaterThan(0.0, (float) $slip->philhealth, 'PhilHealth is second');
        $this->assertEqualsWithDelta(0.0, (float) $slip->pagibig, 0.01, 'Pag-IBIG is last and absorbs it');

        // Never negative: a component is collected, partially collected, or not at all.
        foreach (['sss', 'philhealth', 'pagibig', 'other_deductions'] as $component) {
            $this->assertGreaterThanOrEqual(0.0, (float) $slip->$component, "{$component} went negative");
        }
    }

    // --- the report the finding is actually about --------------------------------------------

    /**
     * The reason this matters. The remittance report sums these columns directly, so before the
     * fix it reported contributions that were never withheld — the company would have filed
     * figures its own payslips cannot support.
     */
    public function test_the_remittance_columns_never_exceed_what_was_collected(): void
    {
        // Deliberately the LOW-SALARY fixture, not the big-benefit-share one. Where the shortfall
        // lands on a voluntary deduction the remittable columns are untouched and this assertion
        // holds even on the broken code — it would pass while proving nothing. The statutory
        // columns are only overstated when the shortfall actually reaches them.
        $slip = $this->oneDayOnALowSalary();

        $statutoryAndTax = round(
            (float) $slip->sss + (float) $slip->philhealth
                + (float) $slip->pagibig + (float) $slip->withholding_tax,
            2,
        );

        $this->assertLessThanOrEqual(
            (float) $slip->total_deductions,
            $statutoryAndTax,
            'the remittable columns cannot sum to more than was taken off the employee',
        );

        // Pag-IBIG went uncollected, so the column the remittance sums must read ₱0 — not the
        // ₱50 that was owed. Remitting ₱50 the employee never paid is the filing error FIN-053
        // describes, and it is this column that produces it.
        $this->assertEqualsWithDelta(0.0, (float) $slip->pagibig, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $slip->deduction_carryover, 0.01);
    }
}
