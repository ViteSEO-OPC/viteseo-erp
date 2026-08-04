<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

/**
 * Also exercises FIN-025: the delay is recorded effective 2026-01-01, BEFORE the cycle it prices.
 * Settings are now resolved as of the cycle end, so a change dated today would (correctly) not
 * reach back into a March cycle.
 *
 * FIN-036 — a payslip's stated pay date used to move after it was issued.
 *
 * Nothing recorded when a run was due to pay. `PayslipResource::payDate()` derived it every time
 * it was read, as `cycle_end + pay_delay_days`, taking the delay from the run's `policy_snapshot`
 * if there was one and from the LIVE setting otherwise. Change `pay_delay_days` and every payslip
 * without a snapshot restated its own pay date — including ones the employee already had a copy
 * of. A payslip is a legally consequential document; it must say the same thing tomorrow.
 *
 * The date is now stamped on the run when the run is created, and read back rather than
 * recomputed. Deliberately named `scheduled_pay_date`: it is when payment was PLANNED. When money
 * actually moved is not something this system can observe today — `markDone()` is a manual
 * assertion — and that belongs on the payment records FIN-015 will introduce.
 */
class ScheduledPayDateTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

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

    private function runPayroll(): \App\Modules\Hr\Models\PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    private function statedPayDate(): ?string
    {
        $slip = Payslip::where('employee_id', $this->employee->id)->firstOrFail();

        return (new PayslipResource($slip))->toArray(Request::create('/'))['pay_date'] ?? null;
    }

    public function test_the_run_records_the_date_it_was_scheduled_to_pay(): void
    {
        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['pay_delay_days' => 5]);

        $run = $this->runPayroll();

        $this->assertSame('2026-03-20', $run->scheduled_pay_date->format('Y-m-d'), 'cycle_end + 5 days');
    }

    /** THE BUG: changing the delay used to rewrite the date on a payslip already issued. */
    public function test_changing_the_pay_delay_does_not_move_an_existing_payslips_pay_date(): void
    {
        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['pay_delay_days' => 5]);
        $this->runPayroll();

        $stated = $this->statedPayDate();
        $this->assertSame('2026-03-20', $stated);

        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['pay_delay_days' => 30]);

        $this->assertSame(
            $stated,
            $this->statedPayDate(),
            'the payslip restated its pay date after the setting changed',
        );
    }

    /** A re-run refreshes the amounts, not the promise about when they arrive. */
    public function test_a_re_run_keeps_the_original_scheduled_pay_date(): void
    {
        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['pay_delay_days' => 5]);
        $first = $this->runPayroll();

        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['pay_delay_days' => 30]);
        $second = $this->runPayroll();

        $this->assertSame($first->id, $second->id, 'precondition: the same run was recomputed');
        $this->assertSame('2026-03-20', $second->scheduled_pay_date->format('Y-m-d'));
    }
}
