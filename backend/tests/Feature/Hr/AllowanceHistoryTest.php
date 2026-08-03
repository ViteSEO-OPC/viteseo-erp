<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\BenefitProgramVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\BenefitProgramService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B11 — an allowance remembers what it used to be worth.
 *
 * THE GAP. Re-running an old cycle priced its allowances at TODAY's figure. Salary has been safe
 * since payroll Slice 13c and the statutory rates since B3a; allowances were the third pricing
 * input and the only one with no memory. Raise Meal Allowance in September, re-run August, and
 * August quietly paid September's amount.
 *
 * Bounded before it was built — a disbursed payslip cannot be recomputed and the breakdown is
 * snapshotted — but 13 computed payslips were exposed on the live database, and that number grows
 * every cycle until they are paid out.
 */
class AllowanceHistoryTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

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

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'monthly',
            'is_management' => false, 'employment_status' => 'regular',
        ]);
    }

    private function allowance(float $amount = 2000): BenefitProgram
    {
        return app(BenefitProgramService::class)->create([
            'name' => 'Meal Allowance', 'kind' => 'allowance', 'category' => 'allowance',
            'amount' => $amount, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all', 'effective_from' => '2026-01-01',
        ]);
    }

    private function allowanceOn(string $cycleEnd): float
    {
        return app(PayrollService::class)->payslipFor(
            employee: $this->employee->fresh(),
            reimb: 0.0, unpaidDays: 0.0, periodsPerYear: 12, workingDaysInPeriod: 21,
            dist: [
                'index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every',
                'cycle_start' => substr($cycleEnd, 0, 8) . '01', 'cycle_end' => $cycleEnd,
                'other_benefits_annual_cap' => 90000,
            ],
        )['allowances'];
    }

    // --- the promise ------------------------------------------------------------------

    /** THE BUG: an old cycle used to reprice at today's figure. */
    public function test_an_old_cycle_keeps_the_amount_that_applied_then(): void
    {
        $program = $this->allowance(2000);

        app(BenefitProgramService::class)->update($program, [
            'amount' => 3000,
            'effective_from' => '2026-09-01',
        ]);

        $this->assertSame(2000.0, $this->allowanceOn('2026-08-31'), 'August must stay on ₱2,000');
        $this->assertSame(3000.0, $this->allowanceOn('2026-09-30'), 'September gets the rise');
    }

    public function test_the_opening_version_covers_everything_before_the_first_change(): void
    {
        $this->allowance(2000);

        // Created effective 2026-01-01; a cycle before that still has to price to something
        // rather than paying zero.
        $this->assertSame(2000.0, $this->allowanceOn('2025-06-30'));
    }

    public function test_a_rise_does_not_reach_a_cycle_that_ended_before_it(): void
    {
        $program = $this->allowance(2000);
        app(BenefitProgramService::class)->update($program, [
            'amount' => 5000, 'effective_from' => '2026-10-01',
        ]);

        $this->assertSame(2000.0, $this->allowanceOn('2026-09-30'));
        $this->assertSame(5000.0, $this->allowanceOn('2026-10-31'));
    }

    // --- what is and is not a new price -----------------------------------------------

    public function test_renaming_an_allowance_does_not_create_a_version(): void
    {
        $program = $this->allowance(2000);
        $before = BenefitProgramVersion::count();

        app(BenefitProgramService::class)->update($program, ['name' => 'Meal Benefit']);

        $this->assertSame($before, BenefitProgramVersion::count(), 'A rename is not a price change.');
    }

    public function test_changing_the_tax_treatment_does_create_a_version(): void
    {
        $program = $this->allowance(2000);
        $before = BenefitProgramVersion::count();

        app(BenefitProgramService::class)->update($program, [
            'tax_treatment' => 'taxable', 'effective_from' => '2026-09-01',
        ]);

        $this->assertSame($before + 1, BenefitProgramVersion::count());
    }

    /** Correcting a figure typed a minute ago must not fail on the unique index. */
    public function test_saving_twice_on_one_date_corrects_that_version(): void
    {
        $program = $this->allowance(2000);
        $service = app(BenefitProgramService::class);

        $service->update($program, ['amount' => 3000, 'effective_from' => '2026-09-01']);
        $service->update($program->fresh(), ['amount' => 3500, 'effective_from' => '2026-09-01']);

        $this->assertSame(
            1,
            BenefitProgramVersion::where('effective_from', '2026-09-01')->count(),
        );
        $this->assertSame(3500.0, $this->allowanceOn('2026-09-30'));
    }

    /** A per-employee amount is one person's arrangement, not a company price. */
    public function test_a_per_employee_override_still_wins_over_the_dated_amount(): void
    {
        $program = $this->allowance(2000);

        app(BenefitProgramService::class)->setAllowanceAmount(
            $program,
            $this->employee,
            4000,
            User::factory()->create()->id,
        );

        app(BenefitProgramService::class)->update($program, [
            'amount' => 3000, 'effective_from' => '2026-09-01',
        ]);

        $this->assertSame(4000.0, $this->allowanceOn('2026-08-31'));
        $this->assertSame(4000.0, $this->allowanceOn('2026-09-30'));
    }

    /** Today's screens ask for today, and must not need a date to do it. */
    public function test_reading_without_a_date_uses_the_current_amount(): void
    {
        $program = $this->allowance(2000);
        app(BenefitProgramService::class)->update($program, [
            'amount' => 3000, 'effective_from' => '2020-01-01',
        ]);

        $this->assertSame(
            3000.0,
            app(\App\Modules\Hr\Services\AllowanceService::class)->monthlyFor($this->employee->fresh()),
        );
    }
}
