<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Slice 5 (2026-07-28 audit) — money already paid out must stop moving.
 *
 * `run()` recomputes payslips in place, which is what makes a re-run safe while a cycle is
 * still open. But it also rewrote the AMOUNTS on payslips that were already disbursed (only
 * the status was preserved), so editing a salary and re-running an old cycle silently
 * changed what someone had been paid.
 *
 * Two layers: a disbursed payslip is skipped by every recompute, and a fully-disbursed run
 * can be locked so it refuses to recompute at all.
 */
class PayrollRunLockTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->workedEveryDay();
    }

    /**
     * A full attendance record for the cycle. Without it every workday is unpaid, base pay
     * floors at zero, and the "amounts didn't change" assertions would pass on 0.0 == 0.0 —
     * proving nothing.
     */
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

    private function runPayroll(): PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    private function slip(): Payslip
    {
        return Payslip::where('employee_id', $this->employee->id)->firstOrFail();
    }

    /** THE BUG: a raise plus a re-run used to rewrite an already-paid payslip. */
    public function test_a_disbursed_payslip_keeps_its_amounts_across_a_re_run(): void
    {
        $this->runPayroll();
        $paid = $this->slip();
        app(PayrollService::class)->markDone($paid);
        $originalGross = (float) $paid->gross;
        $this->assertGreaterThan(0.0, $originalGross); // the assertion below must have teeth

        // A big raise — the recompute would otherwise pick it up.
        $this->employee->update(['base_salary' => 90000]);
        $this->runPayroll();

        $this->assertSame($originalGross, (float) $this->slip()->fresh()->gross);
        $this->assertSame('disbursed', $this->slip()->fresh()->status);
    }

    /** An un-disbursed payslip is still refreshed — that's the point of a re-run. */
    public function test_a_computed_payslip_is_still_recomputed(): void
    {
        $this->runPayroll();
        $before = (float) $this->slip()->gross;

        $this->employee->update(['base_salary' => 90000]);
        $this->runPayroll();

        $this->assertNotSame($before, (float) $this->slip()->fresh()->gross);
    }

    public function test_a_fully_disbursed_run_can_be_locked(): void
    {
        $run = $this->runPayroll();
        app(PayrollService::class)->markDone($this->slip());

        $locked = app(PayrollService::class)->lock($run->fresh(), User::factory()->create());

        $this->assertTrue($locked->isLocked());
        $this->assertNotNull($locked->locked_by);
    }

    /** Locking a half-paid run would strand the rest, since a locked run can't recompute. */
    public function test_a_run_with_undisbursed_payslips_cannot_be_locked(): void
    {
        $run = $this->runPayroll();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not disbursed yet');
        app(PayrollService::class)->lock($run, User::factory()->create());
    }

    public function test_a_locked_run_refuses_to_recompute(): void
    {
        $run = $this->runPayroll();
        app(PayrollService::class)->markDone($this->slip());
        app(PayrollService::class)->lock($run->fresh(), User::factory()->create());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('locked');
        $this->runPayroll();
    }

    public function test_unlocking_lets_it_recompute_again(): void
    {
        $run = $this->runPayroll();
        app(PayrollService::class)->markDone($this->slip());
        app(PayrollService::class)->lock($run->fresh(), User::factory()->create());
        app(PayrollService::class)->unlock($run->fresh(), User::factory()->create());

        $this->runPayroll(); // no exception

        $this->assertFalse($run->fresh()->isLocked());
    }

    public function test_an_empty_run_cannot_be_locked(): void
    {
        $run = PayrollRun::create([
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-15',
            'frequency' => 'semi_monthly', 'status' => 'completed',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('nothing to lock');
        app(PayrollService::class)->lock($run, User::factory()->create());
    }

    /** Skipped payslips still count toward the run's headline totals. */
    public function test_run_totals_include_skipped_disbursed_payslips(): void
    {
        $this->runPayroll();
        app(PayrollService::class)->markDone($this->slip());
        $gross = (float) $this->slip()->fresh()->gross;

        $rerun = $this->runPayroll();

        $this->assertSame(1, $rerun->employee_count);
        $this->assertSame($gross, (float) $rerun->total_gross);
    }

    public function test_the_lock_endpoint_is_reachable_and_reports_state(): void
    {
        $run = $this->runPayroll();
        app(PayrollService::class)->markDone($this->slip());

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/lock", [])
            ->assertOk()
            ->assertJsonPath('data.is_locked', true);

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/unlock", [])
            ->assertOk()
            ->assertJsonPath('data.is_locked', false);
    }
}
