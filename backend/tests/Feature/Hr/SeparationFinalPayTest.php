<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice 4 (2026-07-28 audit) — final pay for a leaver, and never destroying paid history.
 *
 * A separation is `separation_type` + a soft-delete, so a leaver was invisible to the run
 * twice over and got NO payslip for the days they had worked. Worse, re-running an older
 * cycle afterwards force-deleted the payslip they had already been paid from.
 *
 * The cycle here (2026-03-01 → 03-15) is entirely in the past, so every scheduled workday
 * with no attendance record counts as unpaid — which makes the numbers easy to reason about:
 * what matters is that the leaver is INCLUDED, and that days after their last one are never
 * treated as owed.
 */
class SeparationFinalPayTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    /** @param array<string, mixed> $attrs */
    private function employee(array $attrs = []): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => 'E-' . fake()->unique()->numerify('###'),
            'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ] + $attrs);
    }

    private function runPayroll(): \App\Modules\Hr\Models\PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    /** A leaver mid-cycle still gets their final payslip. */
    public function test_an_employee_separated_mid_cycle_still_gets_a_payslip(): void
    {
        $leaver = $this->employee(['separation_type' => 'voluntary', 'separation_date' => '2026-03-10']);
        $leaver->delete(); // separations are soft-deleted, as the app records them

        $this->runPayroll();

        $this->assertDatabaseHas('hr_payslips', ['employee_id' => $leaver->id]);
    }

    /** Someone who left BEFORE the cycle started is not paid for it. */
    public function test_an_employee_separated_before_the_cycle_is_excluded(): void
    {
        $gone = $this->employee(['separation_type' => 'voluntary', 'separation_date' => '2026-02-01']);
        $gone->delete();

        $this->runPayroll();

        $this->assertDatabaseMissing('hr_payslips', ['employee_id' => $gone->id]);
    }

    /** A separation with no recorded last day is never guessed at. */
    public function test_a_separation_without_a_date_stays_excluded(): void
    {
        $unknown = $this->employee(['separation_type' => 'voluntary']);
        $unknown->delete();

        $this->runPayroll();

        $this->assertDatabaseMissing('hr_payslips', ['employee_id' => $unknown->id]);
    }

    /**
     * Days after the last working day are unpaid even when they are still in the FUTURE —
     * the "no record yet, don't charge it" rule used to pay a leaver for them.
     */
    public function test_days_after_the_last_day_are_not_owed(): void
    {
        $svc = app(AttendanceService::class);
        $future = now(config('app.timezone_local'))->addMonth();
        [$start, $end] = [$future->copy()->startOfMonth(), $future->copy()->startOfMonth()->addDays(14)];

        $staying = $this->employee();
        $leaver = $this->employee([
            'separation_type' => 'voluntary',
            'separation_date' => $start->toDateString(), // last day = the 1st
        ]);

        $workdays = $svc->scheduledWorkdaysFor($staying, $start->toDateString(), $end->toDateString());

        // Still employed → a future period costs nothing yet.
        $this->assertSame(0.0, $svc->unpaidDaysFor($staying, $start->toDateString(), $end->toDateString()));

        // Left on day one → every scheduled workday after it is unpaid.
        $leaverUnpaid = $svc->unpaidDaysFor($leaver, $start->toDateString(), $end->toDateString());
        $this->assertGreaterThan(0.0, $leaverUnpaid);
        $this->assertSame((float) $workdays - ($start->isWeekday() ? 1.0 : 0.0), $leaverUnpaid);
    }

    /** The divisor stays the FULL period — shrinking it would overpay a part-period employee. */
    public function test_the_workday_divisor_is_not_shrunk_by_a_separation(): void
    {
        $svc = app(AttendanceService::class);
        $staying = $this->employee();
        $leaver = $this->employee(['separation_type' => 'voluntary', 'separation_date' => '2026-03-05']);

        $this->assertSame(
            $svc->scheduledWorkdaysFor($staying, self::CYCLE_START, self::CYCLE_END),
            $svc->scheduledWorkdaysFor($leaver, self::CYCLE_START, self::CYCLE_END),
        );
    }

    /** THE DATA-LOSS PATH: a disbursed payslip survives a re-run that drops the employee. */
    public function test_a_disbursed_payslip_is_never_deleted_by_a_re_run(): void
    {
        $employee = $this->employee();
        $run = $this->runPayroll();

        $slip = Payslip::where('employee_id', $employee->id)->firstOrFail();
        app(PayrollService::class)->markDone($slip); // paid out

        // They leave, backdated before this cycle → the next re-run no longer includes them.
        $employee->update(['separation_type' => 'voluntary', 'separation_date' => '2026-02-01']);
        $employee->delete();

        $this->runPayroll();

        $this->assertDatabaseHas('hr_payslips', ['id' => $slip->id, 'status' => 'disbursed']);
    }

    /** An un-disbursed payslip for someone no longer in the run is still cleaned up. */
    public function test_a_computed_payslip_is_still_cleaned_up(): void
    {
        $employee = $this->employee();
        $this->runPayroll();

        $slip = Payslip::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('computed', $slip->status);

        $employee->update(['separation_type' => 'voluntary', 'separation_date' => '2026-02-01']);
        $employee->delete();

        $this->runPayroll();

        $this->assertDatabaseMissing('hr_payslips', ['id' => $slip->id]);
    }
}
