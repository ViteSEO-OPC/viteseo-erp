<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B10 — an allowance is prorated for the part of the period actually worked.
 *
 * THE BUG. Basic pay was prorated for the days somebody was employed; the allowance was not. A
 * hire starting on the 25th received a whole month's allowance, and a leaver received one for a
 * month they were mostly gone. It is wrong on the first and last payslip anybody gets — the two
 * most closely read payslips in a career.
 *
 * WHAT IS NOT PRORATED, deliberately: ABSENCE. An allowance is a fixed benefit rather than
 * something earned per day attended, so unpaid days leave it alone. The distinction this slice
 * turns on is "not employed yet" versus "employed and away".
 */
class AllowanceProrationTest extends HrTestCase
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

        BenefitProgram::create([
            'name' => 'Meal Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 4000, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function employee(string $no, array $attributes = []): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'monthly',
            'is_management' => false, 'employment_status' => 'regular',
            // `array_merge`, NOT `+`: the union operator keeps the LEFT value for a duplicate key,
            // so `hire_date` overrides were silently dropped and two of these tests passed for the
            // wrong reason.
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function slip(Employee $employee, float $unpaidDays = 0.0): array
    {
        // August 2026: 21 scheduled workdays (Mon–Fri).
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
        );
    }

    public function test_a_full_period_employee_gets_the_whole_allowance(): void
    {
        $this->assertSame(4000.0, $this->slip($this->employee('E-001'))['allowances']);
    }

    /** THE BUG: someone hired on the 25th used to receive a full month. */
    public function test_a_mid_cycle_hire_gets_only_the_part_they_were_employed(): void
    {
        // Hired 2026-08-25 — 5 of August's 21 workdays (25, 26, 27, 28, 31).
        $hire = $this->employee('E-002', ['hire_date' => '2026-08-25']);

        $this->assertSame(round(4000 * 5 / 21, 2), $this->slip($hire)['allowances']);
    }

    public function test_a_leaver_stops_receiving_it_after_their_last_day(): void
    {
        // Left 2026-08-07 — 5 workdays (3, 4, 5, 6, 7).
        $leaver = $this->employee('E-003', [
            'separation_date' => '2026-08-07', 'separation_type' => 'resigned',
        ]);

        $this->assertSame(round(4000 * 5 / 21, 2), $this->slip($leaver)['allowances']);
    }

    /** The distinction the slice turns on: being away is not the same as not being employed. */
    public function test_absence_still_does_not_reduce_the_allowance(): void
    {
        $employee = $this->employee('E-004');

        $this->assertSame(4000.0, $this->slip($employee, unpaidDays: 5.0)['allowances']);
    }

    /** The breakdown has to add up to the figure actually paid. */
    public function test_the_payslip_breakdown_sums_to_the_prorated_total(): void
    {
        BenefitProgram::create([
            'name' => 'Transport Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 2000, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);

        $slip = $this->slip($this->employee('E-005', ['hire_date' => '2026-08-25']));
        $lines = array_sum(array_column($slip['allowances_breakdown'], 'amount'));

        $this->assertSame($slip['allowances'], round($lines, 2));
        $this->assertNotEmpty($slip['allowances_breakdown']);
    }

    public function test_someone_hired_after_the_period_gets_none_of_it(): void
    {
        $future = $this->employee('E-006', ['hire_date' => '2026-09-15']);

        $this->assertSame(0.0, $this->slip($future)['allowances']);
    }

    /**
     * Management basic pay is never day-prorated (`unpaidDaysDetailFor()` returns 0 for them), so
     * prorating their allowance alone would make one payslip disagree with itself.
     */
    public function test_management_is_left_alone_to_match_their_basic_pay(): void
    {
        $manager = $this->employee('E-007', ['hire_date' => '2026-08-25', 'is_management' => true]);

        $this->assertSame(4000.0, $this->slip($manager)['allowances']);
    }

    /** A part-period employee is taxed on what they were actually paid. */
    public function test_a_taxable_allowance_is_withheld_on_the_prorated_amount(): void
    {
        BenefitProgram::query()->update(['tax_treatment' => 'taxable']);

        $full = $this->slip($this->employee('E-008'));
        $part = $this->slip($this->employee('E-009', ['hire_date' => '2026-08-25']));

        $this->assertLessThan(
            $full['withholding_tax'],
            $part['withholding_tax'],
            'Withholding a full month of an allowance they only part-received is the same error.',
        );
    }
}
