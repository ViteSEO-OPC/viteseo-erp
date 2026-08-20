<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\BenefitProgramVersion;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** A contribution is charged on the salary figure its own version names. */
class ContributionEarningsBasisTest extends HrTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function employee(float $salary = 30000): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => $salary, 'pay_frequency' => 'monthly',
            'is_management' => false, 'employment_status' => 'regular',
        ]);
    }

    /**
     * A flat-percentage version with no floor or ceiling, so the arithmetic below reads as
     * "1% of whatever base was used" and the base is the only thing under test. The seeded
     * schedules all clamp, which is right for them and useless for isolating a base.
     */
    private function version(string $type, string $salaryBasis): ContributionVersion
    {
        return ContributionVersion::create([
            'type' => $type, 'effective_from' => '2026-01-01', 'basis' => 'percentage',
            'salary_basis' => $salaryBasis,
            'employee_rate' => 0.01, 'employer_rate' => 0.02,
        ]);
    }

    /**
     * August 2026: 21 scheduled workdays, monthly payroll, and `count: 1` so the whole monthly
     * contribution lands on the slip and the figures read as published monthly amounts.
     *
     * @return array<string, mixed>
     */
    private function slip(Employee $employee, float $unpaidDays = 0.0, float $overtimePay = 0.0): array
    {
        return app(PayrollService::class)->payslipFor(
            employee: $employee->fresh(),
            reimb: 0.0,
            unpaidDays: $unpaidDays,
            periodsPerYear: 12,
            workingDaysInPeriod: 21,
            dist: [
                'index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every',
                'cycle_start' => '2026-08-01', 'cycle_end' => '2026-08-31',
                'other_benefits_annual_cap' => 90000,
            ],
            overtimePay: $overtimePay,
        );
    }

    private function allowance(string $name, float $amount, bool $contributable): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => $name, 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => $amount, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all', 'contributable' => $contributable,
        ]);
    }

    // --- the default is unchanged behaviour -------------------------------------------

    /**
     * THE SAFETY PROPERTY. Nothing in this slice may move a number until someone records a version
     * that asks for it — otherwise every completed run silently reprices on the next re-run.
     */
    public function test_a_version_with_no_basis_recorded_charges_on_basic_salary(): void
    {
        $this->version('sss', 'basic');
        $employee = $this->employee(30000);

        // Five unpaid days out of 21 — basic pay drops, the contribution does not.
        $this->assertSame(300.0, $this->slip($employee, unpaidDays: 5)['sss'], '1% of ₱30,000');
    }

    public function test_the_column_defaults_to_basic_so_existing_versions_are_untouched(): void
    {
        $version = ContributionVersion::create([
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'percentage',
            'employee_rate' => 0.01, 'employer_rate' => 0.02,
        ]);

        $this->assertSame('basic', $version->fresh()->salary_basis);
        $this->assertSame('basic', $version->fresh()->toConfig()['salary_basis']);
    }

    // --- the earnings basis ------------------------------------------------------------

    /** THE POINT: absences reduce what the employee actually earned, so they reduce the base. */
    public function test_absences_reduce_the_contribution_on_the_earnings_basis(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        // ₱30,000 ÷ 21 workdays = ₱1,428.57/day. Five unpaid days = ₱7,142.85 off.
        $slip = $this->slip($employee, unpaidDays: 5);

        $this->assertSame(22857.15, (float) $slip['base_salary'], 'the earnings the base follows');
        $this->assertSame(228.57, (float) $slip['sss'], '1% of what was actually earned');
        // What the old code would have charged, and the whole reason this slice exists.
        $this->assertNotSame(300.0, (float) $slip['sss']);
    }

    /** Overtime is remuneration, so it RAISES the base — the defect cut both ways. */
    public function test_overtime_raises_the_contribution_on_the_earnings_basis(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        $slip = $this->slip($employee, overtimePay: 5000);

        $this->assertSame(350.0, (float) $slip['sss'], '1% of ₱35,000 earned');
    }

    /** The employer's share follows the same base — it is the other half of one remittance. */
    public function test_the_employer_share_moves_with_the_same_base(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        $slip = $this->slip($employee, unpaidDays: 5);

        $this->assertSame(457.14, (float) $slip['employer_sss'], '2% of ₱22,857.15');
    }

    /**
     * A REGRESSION GUARD THAT MATTERS. PhilHealth on `basic` and SSS on `earnings` is the
     * RECOMMENDED configuration, not an edge case, so the two must genuinely resolve different
     * figures on one payslip. A single shared base would pass every other test in this file.
     */
    public function test_two_contributions_can_use_different_bases_on_one_payslip(): void
    {
        $this->version('sss', 'earnings');
        $this->version('philhealth', 'basic');
        $employee = $this->employee(30000);

        $slip = $this->slip($employee, unpaidDays: 5);

        $this->assertSame(228.57, (float) $slip['sss'], 'earnings');
        $this->assertSame(300.0, (float) $slip['philhealth'], 'basic salary, unmoved by absence');
    }

    /** The month's base is its cutoffs ADDED, not one cutoff doubled. */
    public function test_the_final_cutoff_charges_on_the_months_cutoffs_added(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        // The month's SECOND semi-monthly cutoff. The first already paid ₱15,000.
        $slip = $this->semiMonthly($employee, index: 1, monthToDate: [
            'earnings' => 15000.0, 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'periods' => 1,
        ]);

        $this->assertSame(15000.0, (float) $slip['base_salary'], 'half a month of pay');
        $this->assertSame(300.0, (float) $slip['sss'], '1% of the ₱30,000 the month actually paid');
    }

    /**
     * A HALF-MONTH OF ABSENCE MUST REACH THE MONTH'S BASE. This is the case the old arithmetic
     * could not express: the first cutoff was normal, the second was not, and the month is the
     * average of the two rather than either one doubled.
     */
    public function test_absence_in_one_cutoff_lowers_the_whole_months_base(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        // First cutoff paid in full (₱15,000); this one loses 5 days to unpaid leave.
        $slip = $this->semiMonthly($employee, index: 1, unpaidDays: 5, monthToDate: [
            'earnings' => 15000.0, 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'periods' => 1,
        ]);

        $earned = 15000.0 + (float) $slip['base_salary'];

        $this->assertLessThan(30000.0, $earned, 'the month earned less than a full one');
        $this->assertSame(round($earned * 0.01, 2), (float) $slip['sss'], '1% of what the month paid');
    }

    /**
     * BEFORE THE MONTH IS FINISHED there is nothing to add up, so the provisional cutoff scales
     * ITS OWN earnings to a month — `period pay × cutoffs` — rather than assuming the contract was
     * met. Someone who lost half the period is not charged as though they had worked it all, which
     * also leaves a smaller correction for the final cutoff.
     */
    public function test_a_provisional_cutoff_is_charged_on_its_own_earnings_scaled_to_a_month(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        $slip = $this->semiMonthly($employee, index: 0, unpaidDays: 5);

        $periodPay = (float) $slip['base_salary'];
        // base = periodPay × 2, charged at 1% for the month, of which this cutoff takes half.
        $expected = round(($periodPay * 2) * 0.01 / 2, 2);

        $this->assertSame($expected, (float) $slip['sss']);
        $this->assertLessThan(
            150.0,
            (float) $slip['sss'],
            'and less than the ₱150 the contracted salary would have charged — the absence reached it',
        );
    }

    /**
     * A capped contribution takes at most its SHARE of the monthly ceiling on one cutoff, never
     * the whole ceiling per cutoff.
     */
    public function test_a_capped_contribution_takes_only_its_share_of_the_ceiling(): void
    {
        ContributionVersion::create([
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'percentage',
            'salary_basis' => 'earnings', 'employee_rate' => 0.05, 'employer_rate' => 0.10,
            'salary_cap' => 35000,
        ]);

        // ₱95,000 a month — every cutoff is already past the ₱35,000 ceiling on its own.
        $employee = $this->employee(95000);
        $slip = $this->semiMonthly($employee, index: 0);

        // The month's cap is 5% × ₱35,000 = ₱1,750, so ONE cutoff may take at most half of it.
        $this->assertSame(875.0, (float) $slip['sss'], 'half the monthly ceiling, not the whole of it');
    }

    /**
     * THE TRUE-UP. The final cutoff collects the month's real total MINUS what the earlier cutoffs
     * already took, which is what lets a split deduction land on a published figure despite a
     * provisional first half.
     */
    public function test_the_final_cutoff_trues_up_what_earlier_cutoffs_collected(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        // The month really earned ₱30,000 (₱15,000 + ₱15,000) so ₱300 is due for the month.
        // ₱150 was already taken on the first cutoff, so this one takes the remaining ₱150.
        $slip = $this->semiMonthly($employee, index: 1, monthToDate: [
            'earnings' => 15000.0, 'sss' => 150.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'periods' => 1,
        ]);

        $this->assertSame(150.0, (float) $slip['sss'], '₱300 due for the month, ₱150 already paid');
    }

    /** OVER-COLLECTED EARLIER IN THE MONTH ⇒ THE DIFFERENCE COMES BACK. */
    public function test_over_collection_is_refunded_on_the_final_cutoff(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        // ₱300 due for the month, ₱500 already taken ⇒ ₱200 back.
        $slip = $this->semiMonthly($employee, index: 1, monthToDate: [
            'earnings' => 15000.0, 'sss' => 500.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'periods' => 1,
        ]);

        $this->assertSame(-200.0, (float) $slip['sss'], 'the ₱200 over-collected comes back');
    }

    /**
     * A CUTOFF THAT WAS NEVER RUN IS A HOLE, NOT A ZERO. Running only the second cutoff of a month
     * must not charge SSS on half a month's pay; the missing half is filled from the contract.
     */
    public function test_a_cutoff_that_was_never_run_is_filled_from_the_contract(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);

        // Second cutoff, but the first was never run — no prior payslip at all.
        $slip = $this->semiMonthly($employee, index: 1);

        $this->assertSame(300.0, (float) $slip['sss'], 'the missing half counted at contract rate');
    }

    /**
     * One semi-monthly cutoff of August 2026. `index` picks which of the month's two it is;
     * `monthToDate` is what the earlier cutoffs recorded, as `run()` preloads it.
     *
     * @param  array<string, mixed>  $monthToDate
     * @return array<string, mixed>
     */
    private function semiMonthly(Employee $employee, int $index, float $unpaidDays = 0.0, array $monthToDate = []): array
    {
        $dist = [
            'index' => $index, 'count' => 2,
            'deduction_timing' => 'every', 'tax_timing' => 'every',
            'cycle_start' => $index === 0 ? '2026-08-01' : '2026-08-16',
            'cycle_end' => $index === 0 ? '2026-08-15' : '2026-08-31',
        ];

        if ($monthToDate !== []) {
            $dist['month_to_date'] = $monthToDate;
        }

        return app(PayrollService::class)->payslipFor(
            employee: $employee->fresh(),
            reimb: 0.0, unpaidDays: $unpaidDays, periodsPerYear: 24, workingDaysInPeriod: 11,
            dist: $dist,
        );
    }

    // --- floors and ceilings still hold ------------------------------------------------

    /**
     * A HEAVY-ABSENCE MONTH MUST NOT WIPE A STATUTORY DEDUCTION. The floors have existed since
     * B3a but were never load-bearing: the base was always the full salary, so nothing could fall
     * through them. Making the base movable is what puts them to work.
     */
    public function test_the_salary_floor_holds_when_earnings_collapse(): void
    {
        ContributionVersion::create([
            'type' => 'philhealth', 'effective_from' => '2026-01-01', 'basis' => 'percentage',
            'salary_basis' => 'earnings', 'employee_rate' => 0.025, 'employer_rate' => 0.025,
            'salary_floor' => 10000, 'salary_cap' => 100000,
        ]);
        // An EXCLUDED allowance, so there is gross pay for the deduction to come out of without
        // it entering the base. Without this the payslip's own shortfall rule caps the ₱250 at
        // whatever gross can afford and the floor's work is hidden behind the carryover.
        $this->allowance('Rice Subsidy', 5000, contributable: false);

        // Every scheduled day unpaid: basic pay is a rounding residue of the daily rate
        // (₱30,000 − 21 × ₱1,428.57), which is as close to nothing earned as the schedule allows.
        $slip = $this->slip($this->employee(30000), unpaidDays: 21);

        $this->assertSame(0.03, (float) $slip['base_salary'], 'effectively nothing earned');
        $this->assertSame(0.03, $slip['contribution_bases']['philhealth'], 'the raw base');
        $this->assertSame(250.0, (float) $slip['philhealth'], '2.5% of the ₱10,000 floor, not ₱0');
    }

    /** And a bracket table's lowest band does the same job for SSS. */
    public function test_the_lowest_bracket_holds_when_earnings_collapse(): void
    {
        $version = ContributionVersion::create([
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'salary_basis' => 'earnings', 'employee_rate' => 0.05, 'employer_rate' => 0.10,
        ]);
        $version->brackets()->createMany([
            ['min_salary' => 0, 'msc' => 5000],
            ['min_salary' => 29750, 'msc' => 30000],
        ]);
        $this->allowance('Rice Subsidy', 5000, contributable: false);

        $slip = $this->slip($this->employee(30000), unpaidDays: 21);

        $this->assertSame(250.0, (float) $slip['sss'], '5% of the ₱5,000 lowest MSC, not ₱0');
    }

    /**
     * AND WHEN THERE IS GENUINELY NOTHING TO TAKE IT FROM, the floored contribution is still
     * computed and the existing shortfall rule records what could not be collected. The failure
     * to avoid is a statutory deduction silently becoming ₱0 — not one that is owed and chased.
     */
    public function test_a_floored_contribution_that_gross_cannot_cover_becomes_an_arrear(): void
    {
        ContributionVersion::create([
            'type' => 'philhealth', 'effective_from' => '2026-01-01', 'basis' => 'percentage',
            'salary_basis' => 'earnings', 'employee_rate' => 0.025, 'employer_rate' => 0.025,
            'salary_floor' => 10000, 'salary_cap' => 100000,
        ]);

        // Only PhilHealth, so the carryover below is unambiguously its own — the seeder enrols
        // all three and SSS alone would contribute ₱1,500 to the shortfall.
        $employee = $this->employee(30000);
        $employee->benefits()->create(['contribution_type' => 'sss', 'enrolled' => false]);
        $employee->benefits()->create(['contribution_type' => 'pagibig', 'enrolled' => false]);

        $slip = $this->slip($employee, unpaidDays: 21);

        $this->assertSame(0.03, (float) $slip['gross'], 'nothing to deduct from');
        $this->assertSame(249.97, (float) $slip['deduction_carryover'], 'the rest is owed, not waived');
        $this->assertSame(249.97, $slip['deduction_carryover_breakdown']['philhealth']);
        $this->assertSame(
            250.0,
            round((float) $slip['philhealth'] + $slip['deduction_carryover_breakdown']['philhealth'], 2),
            'collected + owed = the floored contribution; none of it was silently waived',
        );
    }

    /** The ceiling is unaffected by the change of base — high earners were already capped. */
    public function test_the_salary_ceiling_still_caps_a_raised_base(): void
    {
        ContributionVersion::create([
            'type' => 'pagibig', 'effective_from' => '2026-01-01', 'basis' => 'percentage',
            'salary_basis' => 'earnings', 'employee_rate' => 0.02, 'employer_rate' => 0.02,
            'salary_cap' => 10000,
        ]);

        $slip = $this->slip($this->employee(30000), overtimePay: 20000);

        $this->assertSame(200.0, (float) $slip['pagibig'], '2% of the ₱10,000 fund-salary ceiling');
    }

    // --- the allowance exclusion switch -------------------------------------------------

    public function test_a_contributable_allowance_joins_the_earnings_base(): void
    {
        $this->version('sss', 'earnings');
        $this->allowance('Meal Allowance', 4000, contributable: true);

        $slip = $this->slip($this->employee(30000));

        $this->assertSame(4000.0, (float) $slip['allowances'], 'paid in full');
        $this->assertSame(340.0, (float) $slip['sss'], '1% of ₱34,000');
    }

    /**
     * THE FEATURE: excluded from the contribution base, PAID IN FULL. The second assertion is the
     * one that matters — an exclusion that quietly reduced take-home would be a pay cut wearing a
     * compliance setting's clothes.
     */
    public function test_an_excluded_allowance_is_paid_in_full_but_stays_out_of_the_base(): void
    {
        $this->version('sss', 'earnings');
        $this->allowance('Rice Subsidy', 4000, contributable: false);

        $slip = $this->slip($this->employee(30000));

        $this->assertSame(300.0, (float) $slip['sss'], '1% of ₱30,000 — the allowance is excluded');
        $this->assertSame(4000.0, (float) $slip['allowances'], 'still paid in full');
        $this->assertSame(34000.0, (float) $slip['gross'], 'and still in gross pay');
        $this->assertSame(
            round(34000.0 - (float) $slip['total_deductions'], 2),
            (float) $slip['net_pay'],
            'the employee receives every peso of it',
        );
    }

    /** Mixed allowances split correctly rather than all-or-nothing. */
    public function test_only_the_excluded_allowance_is_left_out(): void
    {
        $this->version('sss', 'earnings');
        $this->allowance('Meal Allowance', 4000, contributable: true);
        $this->allowance('Rice Subsidy', 2000, contributable: false);

        $slip = $this->slip($this->employee(30000));

        $this->assertSame(6000.0, (float) $slip['allowances'], 'both paid');
        $this->assertSame(340.0, (float) $slip['sss'], '1% of ₱34,000 — meal in, rice out');
    }

    /** The flag is inert while the contribution is on the basic basis, and must not misfire. */
    public function test_the_flag_does_nothing_on_the_basic_basis(): void
    {
        $this->version('sss', 'basic');
        $this->allowance('Rice Subsidy', 4000, contributable: false);

        $this->assertSame(300.0, (float) $this->slip($this->employee(30000))['sss']);
    }

    /**
     * Independent of the tax switch. A taxable allowance can sit outside the SSS base and the
     * mandated COLA is de-minimis for tax but inside it — folding the two into one flag would
     * force a wrong answer on one of them.
     */
    public function test_contributability_is_independent_of_tax_treatment(): void
    {
        $this->version('sss', 'earnings');
        BenefitProgram::create([
            'name' => 'Taxable Bonus Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 5000, 'frequency' => 'monthly', 'tax_treatment' => 'taxable',
            'eligibility' => 'all', 'contributable' => false,
        ]);

        $slip = $this->slip($this->employee(30000));

        $this->assertSame(300.0, (float) $slip['sss'], 'taxable, but not contributable');
        $this->assertTrue((float) $slip['withholding_tax'] > 0, 'still taxed');
    }

    // --- the snapshot on the payslip ----------------------------------------------------

    /**
     * The base is no longer derivable after the fact: it depends on this period's attendance and
     * premium lines, and on a flag HR can change afterwards. It is the first question a remittance
     * query asks, so it is recorded rather than recomputed.
     */
    public function test_the_payslip_records_what_each_contribution_was_charged_on(): void
    {
        $this->version('sss', 'earnings');
        $this->version('philhealth', 'basic');

        $bases = $this->slip($this->employee(30000), unpaidDays: 5)['contribution_bases'];

        $this->assertSame(22857.15, $bases['sss']);
        $this->assertSame(30000.0, $bases['philhealth']);
    }

    /** A contribution the employee is not enrolled in was charged on nothing, and says so. */
    public function test_an_unenrolled_contribution_records_no_base(): void
    {
        $this->version('sss', 'earnings');
        $employee = $this->employee(30000);
        $employee->benefits()->create(['contribution_type' => 'philhealth', 'enrolled' => false]);

        $bases = $this->slip($employee)['contribution_bases'];

        $this->assertArrayHasKey('sss', $bases);
        $this->assertArrayNotHasKey('philhealth', $bases);
    }

    // --- versioning ---------------------------------------------------------------------

    /**
     * THE HISTORY GUARANTEE. Moving to the earnings basis in September must not change what August
     * was charged — the same property `hr_salary_histories` (13c), the rate versions and the
     * allowance versions each had to establish.
     */
    public function test_an_older_cycle_is_still_priced_on_the_older_basis(): void
    {
        // In force from January: the basic basis.
        $this->version('sss', 'basic');
        // From September: earnings.
        ContributionVersion::create([
            'type' => 'sss', 'effective_from' => '2026-09-01', 'basis' => 'percentage',
            'salary_basis' => 'earnings', 'employee_rate' => 0.01, 'employer_rate' => 0.02,
        ]);

        $employee = $this->employee(30000);

        $sssOn = fn (string $start, string $end) => (float) app(PayrollService::class)->payslipFor(
            employee: $employee->fresh(),
            reimb: 0.0, unpaidDays: 5.0, periodsPerYear: 12, workingDaysInPeriod: 21,
            dist: [
                'index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every',
                'cycle_start' => $start, 'cycle_end' => $end,
            ],
        )['sss'];

        $this->assertSame(300.0, $sssOn('2026-08-01', '2026-08-31'), 'August: still basic salary');
        $this->assertSame(228.57, $sssOn('2026-09-01', '2026-09-30'), 'September: earnings');
    }

    /** The allowance flag is versioned for the same reason, and must behave the same way. */
    public function test_flipping_the_allowance_flag_does_not_reprice_an_older_cycle(): void
    {
        $this->version('sss', 'earnings');
        $program = $this->allowance('Meal Allowance', 4000, contributable: true);

        // The opening version, in force from January: the allowance counts.
        BenefitProgramVersion::create([
            'benefit_program_id' => $program->id,
            'effective_from' => '2026-01-01', 'amount' => 4000, 'frequency' => 'monthly',
            'tax_treatment' => 'de_minimis', 'contributable' => true,
        ]);
        // From September it stops counting.
        BenefitProgramVersion::create([
            'benefit_program_id' => $program->id,
            'effective_from' => '2026-09-01', 'amount' => 4000, 'frequency' => 'monthly',
            'tax_treatment' => 'de_minimis', 'contributable' => false,
        ]);

        $employee = $this->employee(30000);

        $sssOn = fn (string $start, string $end) => (float) app(PayrollService::class)->payslipFor(
            employee: $employee->fresh(),
            reimb: 0.0, unpaidDays: 0.0, periodsPerYear: 12, workingDaysInPeriod: 21,
            dist: [
                'index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every',
                'cycle_start' => $start, 'cycle_end' => $end,
            ],
        )['sss'];

        $this->assertSame(340.0, $sssOn('2026-08-01', '2026-08-31'), 'August: allowance counted');
        $this->assertSame(300.0, $sssOn('2026-09-01', '2026-09-30'), 'September: excluded');
    }

    // --- the endpoints -------------------------------------------------------------------

    public function test_hr_can_record_a_version_on_the_earnings_basis(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'salary_basis' => 'earnings',
            'employee_rate' => 0.01, 'employer_rate' => 0.02,
        ])->assertCreated();

        $this->assertSame(228.57, (float) $this->slip($this->employee(30000), unpaidDays: 5)['sss']);
    }

    public function test_an_unknown_salary_basis_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'salary_basis' => 'net_pay',
            'employee_rate' => 0.01, 'employer_rate' => 0.02,
        ])->assertStatus(422)->assertJsonValidationErrors('salary_basis');
    }

    public function test_hr_can_switch_an_allowance_out_of_the_contribution_base(): void
    {
        $program = $this->allowance('Rice Subsidy', 4000, contributable: true);
        $this->version('sss', 'earnings');

        $this->actingAsHr()->patchJson("/api/v1/hr/benefit-programs/{$program->id}", [
            'contributable' => false,
        ])->assertOk()->assertJsonPath('data.contributable', false);

        $this->assertFalse($program->fresh()->contributable);
    }
}
