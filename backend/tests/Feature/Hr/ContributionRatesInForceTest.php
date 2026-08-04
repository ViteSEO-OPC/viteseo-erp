<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/**
 * FIN-035 — a contribution type with no rate in force silently deducted ₱0.
 *
 * `ContributionService::versionsAsOf()` returns only the types with an `effective_from` on or
 * before the cycle, and `effectiveFor()` filters against that list — so a type with no version
 * DROPS OUT rather than erroring. Payroll then deducted nothing for it and the run completed
 * normally: totals reconciled, payslips looked ordinary, and nothing recorded that a statutory
 * contribution had been skipped. The employee's contribution record is short and the company
 * still owes the agency the full amount.
 *
 * Two entry points, both covered here: a cycle dated before the earliest schedule, and a newly
 * created contribution type that nobody has added a version for yet. Deleting a version was
 * already guarded; creating a type was not.
 */
class ContributionRatesInForceTest extends HrTestCase
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
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function runCycle(string $start = '2026-03-01', string $end = '2026-03-15'): PayrollRun
    {
        return app(PayrollService::class)->run($start, $end, 'semi_monthly', User::factory()->create());
    }

    /** The ordinary case must keep working — this guard must not make payroll harder to run. */
    public function test_a_cycle_with_rates_in_force_runs_normally(): void
    {
        $run = $this->runCycle();

        $this->assertSame('completed', $run->status);
    }

    public function test_a_cycle_before_the_earliest_rate_version_is_refused(): void
    {
        // The seeded opening version starts 2000-01-01; nothing covers 1999.
        $this->expectException(ValidationException::class);

        $this->runCycle('1999-03-01', '1999-03-15');
    }

    public function test_an_active_type_with_no_version_at_all_is_refused(): void
    {
        ContributionSetting::create([
            'type' => 'provident_fund',
            'config' => ['rate' => 0.02, 'employer_rate' => 0.02],
            'is_active' => true,
        ]);

        try {
            $this->runCycle();
            $this->fail('payroll ran with a statutory type that has no rate in force');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'provident_fund',
                implode(' ', $e->validator->errors()->all()),
                'the error must name the type that is missing a rate',
            );
        }
    }

    /** Deactivating the type is the documented way out, and must actually work. */
    public function test_deactivating_the_type_unblocks_the_run(): void
    {
        ContributionSetting::create([
            'type' => 'provident_fund',
            'config' => ['rate' => 0.02],
            'is_active' => false,
        ]);

        $this->assertSame('completed', $this->runCycle()->status);
    }

    /** Adding a version effective on or before the cycle is the other way out. */
    public function test_adding_a_version_in_force_unblocks_the_run(): void
    {
        ContributionSetting::create([
            'type' => 'provident_fund',
            'config' => ['rate' => 0.02],
            'is_active' => true,
        ]);
        ContributionVersion::create([
            'type' => 'provident_fund',
            'effective_from' => '2026-01-01',
            'basis' => 'percentage',
            'employee_rate' => 0.02,
            'employer_rate' => 0.02,
        ]);

        $this->assertSame('completed', $this->runCycle()->status);
    }

    /** No run row may be left behind by a refusal — the guard runs before any write. */
    public function test_a_refused_run_creates_no_payroll_run_row(): void
    {
        ContributionSetting::create([
            'type' => 'provident_fund',
            'config' => ['rate' => 0.02],
            'is_active' => true,
        ]);

        try {
            $this->runCycle();
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, PayrollRun::count(), 'a refused run left a payroll run behind');
    }
}
