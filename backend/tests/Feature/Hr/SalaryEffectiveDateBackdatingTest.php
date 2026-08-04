<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/**
 * FIN-007 — a salary change could only ever take effect today.
 *
 * `EmployeeService::update()` stamped every salary-history row with `now()`. Payroll resolves the
 * salary as of the cycle end, so a raise agreed for 1 August but entered on 5 September priced
 * August at the OLD salary — permanently, because there is no retroactive run to pay the
 * difference through (FIN-010). A promotion agreed for a future date could not be scheduled at all.
 *
 * WHAT IS FIXED HERE: the recorded history is now truthful, and a date that reaches into finished
 * payroll runs is refused unless HR acknowledges it.
 *
 * WHAT IS NOT: there is still no intra-period split. `salariesAsOf($cycleEnd)` applies one salary
 * to the whole period, so a raise dated mid-cutoff prices the whole cutoff at the new figure. That
 * is asserted below rather than left implied — a documented limitation is a different thing from
 * an unknown one.
 */
class SalaryEffectiveDateBackdatingTest extends HrTestCase
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
        SalaryHistory::create([
            'employee_id' => $this->employee->id, 'base_salary' => 30000,
            'effective_date' => '2020-01-01', 'reason' => 'Hire', 'change_type' => 'adjustment',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function raiseTo(float $salary, array $extra = []): Employee
    {
        return app(EmployeeService::class)->update($this->employee, array_merge([
            'base_salary' => $salary,
            'salary_change_reason' => 'Merit increase',
            'salary_change_type' => 'merit',
        ], $extra));
    }

    private function completedRun(string $start, string $end): PayrollRun
    {
        $run = PayrollRun::create([
            'cycle_start' => $start, 'cycle_end' => $end, 'frequency' => 'semi_monthly',
            'type' => 'regular', 'status' => 'completed', 'run_at' => now(),
        ]);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'base_salary' => 30000, 'gross' => 30000,
            'total_deductions' => 0, 'net_pay' => 30000, 'status' => 'computed',
        ]);

        return $run;
    }

    // --- the date is recorded truthfully ------------------------------------------------

    public function test_a_raise_can_be_dated_when_it_actually_took_effect(): void
    {
        $this->raiseTo(40000, ['salary_effective_date' => '2026-08-01']);

        $this->assertSame(
            '2026-08-01',
            SalaryHistory::where('employee_id', $this->employee->id)
                ->latest('id')->first()->effective_date->format('Y-m-d'),
        );
    }

    /** A promotion agreed for a future date can now be scheduled. */
    public function test_a_future_dated_raise_is_recorded(): void
    {
        $this->raiseTo(45000, ['salary_effective_date' => '2027-01-01']);

        $this->assertSame(
            '2027-01-01',
            SalaryHistory::where('employee_id', $this->employee->id)
                ->latest('id')->first()->effective_date->format('Y-m-d'),
        );
    }

    /** Omitting the date keeps the old behaviour exactly: effective today. */
    public function test_omitting_the_date_still_means_today(): void
    {
        $this->raiseTo(35000);

        $this->assertSame(
            now(config('app.timezone_local'))->toDateString(),
            SalaryHistory::where('employee_id', $this->employee->id)
                ->latest('id')->first()->effective_date->format('Y-m-d'),
        );
    }

    // --- reaching into a finished run needs saying ---------------------------------------

    public function test_a_raise_backdated_into_a_completed_run_is_refused(): void
    {
        $this->completedRun('2026-03-01', '2026-03-15');

        try {
            $this->raiseTo(40000, ['salary_effective_date' => '2026-03-01']);
            $this->fail('a back-dated raise silently reached into a completed payroll run');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Mar 1–Mar 15, 2026',
                implode(' ', $e->validator->errors()->all()),
                'the refusal must name the period it would reprice',
            );
        }
    }

    public function test_an_acknowledged_backdated_raise_is_recorded(): void
    {
        $this->completedRun('2026-03-01', '2026-03-15');

        $this->raiseTo(40000, [
            'salary_effective_date' => '2026-03-01',
            'acknowledge_repricing' => true,
        ]);

        $this->assertSame(40000.0, (float) SalaryHistory::where('employee_id', $this->employee->id)
            ->latest('id')->first()->base_salary);
    }

    /** A date after every finished run is ordinary business and must not be obstructed. */
    public function test_a_raise_after_the_last_completed_run_needs_no_confirmation(): void
    {
        $this->completedRun('2026-03-01', '2026-03-15');

        $this->raiseTo(40000, ['salary_effective_date' => '2026-04-01']);

        $this->assertSame(40000.0, (float) $this->employee->fresh()->base_salary);
    }

    // --- the documented limitation --------------------------------------------------------

    /**
     * NOT A BUG, A STATED LIMIT. Pricing resolves one salary per period, as of the cycle end, so a
     * raise dated mid-cutoff prices the WHOLE cutoff at the new figure. Asserted so that if anyone
     * later adds split-period pricing, this test fails and forces the decision to be explicit.
     */
    public function test_a_mid_period_raise_prices_the_whole_period_at_the_new_salary(): void
    {
        $this->raiseTo(60000, ['salary_effective_date' => '2026-03-08']); // mid-cutoff

        $salaries = (new \ReflectionMethod(\App\Modules\Hr\Services\PayrollService::class, 'salariesAsOf'));
        $salaries->setAccessible(true);
        $resolved = $salaries->invoke(
            app(\App\Modules\Hr\Services\PayrollService::class),
            collect([$this->employee]),
            '2026-03-15',
        );

        $this->assertSame(
            60000.0,
            $resolved[$this->employee->id],
            'the whole period resolves to one salary — there is no intra-period split',
        );
    }
}
