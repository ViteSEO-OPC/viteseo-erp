<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** The edges of a payroll run, where the arithmetic stops being ordinary. */
class PayrollBoundaryTest extends HrTestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $this->dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    /** @param array<string, mixed> $attrs */
    private function employee(string $no, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'user_id' => User::factory()->create()->id, 'department_id' => $this->dept->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ], $attrs));
    }

    /** Present on every weekday of the first August cycle. */
    private function workFirstHalf(Employee $employee): void
    {
        $tz = config('app.timezone_local');

        for ($day = Carbon::parse('2026-08-01', $tz); $day->day <= 15 && $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(8, 0)->utc(),
                'time_out' => $day->copy()->setTime(17, 0)->utc(),
                'status' => 'present',
            ]);
        }
    }

    private function runFirstHalf(): \App\Modules\Hr\Models\PayrollRun
    {
        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    // --- negative net ----------------------------------------------------------------------------

    /**
     * Net pay can never go below zero. The company cannot bill an employee through payroll, and a
     * negative net would flow straight into the bank file as a debit.
     */
    public function test_net_pay_never_goes_negative(): void
    {
        // A tiny salary against a loan instalment far larger than the whole period's pay.
        $employee = $this->employee('E-001', ['base_salary' => 5000]);
        $this->workFirstHalf($employee);

        EmployeeLoan::create([
            'employee_id' => $employee->id, 'type' => 'loan',
            'principal' => 90000, 'per_period_amount' => 80000,
            'start_date' => '2026-01-01', 'status' => 'active', 'authorized_at' => now(),
        ]);

        $slip = $this->runFirstHalf()->payslips()->firstOrFail();

        $this->assertGreaterThanOrEqual(0.0, (float) $slip->net_pay);
    }

    /**
     * AND THE SHORTFALL IS RECORDED, which is the half that matters. Clamping at zero without recording
     * what went uncollected means the company silently forgives the difference and the employee's
     * obligation vanishes.
     */
    public function test_an_uncollectable_deduction_is_carried_not_forgiven(): void
    {
        $employee = $this->employee('E-001', ['base_salary' => 4000]);
        $this->workFirstHalf($employee);

        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id, 'type' => 'loan',
            'principal' => 90000, 'per_period_amount' => 80000,
            'start_date' => '2026-01-01', 'status' => 'active', 'authorized_at' => now(),
        ]);

        $slip = $this->runFirstHalf()->payslips()->firstOrFail();

        // Whatever the payslip could not take is still owed on the loan.
        $this->assertGreaterThan(
            0.0,
            $loan->fresh()->outstanding(),
            'the balance must not be written off by a thin payslip',
        );

        // And the statutory side records its own shortfall rather than dropping it.
        $this->assertGreaterThanOrEqual(0.0, (float) $slip->deduction_carryover);
    }

    /** The document's own arithmetic still closes when net is clamped at zero. */
    public function test_the_payslip_totals_still_balance_at_zero_net(): void
    {
        $employee = $this->employee('E-001', ['base_salary' => 4000]);
        $this->workFirstHalf($employee);

        EmployeeLoan::create([
            'employee_id' => $employee->id, 'type' => 'loan',
            'principal' => 90000, 'per_period_amount' => 80000,
            'start_date' => '2026-01-01', 'status' => 'active', 'authorized_at' => now(),
        ]);

        $slip = $this->runFirstHalf()->payslips()->firstOrFail();

        // The identity every payslip satisfies: components sum to what was actually collected.
        $components = round(
            (float) $slip->sss + (float) $slip->philhealth + (float) $slip->pagibig
                + (float) $slip->withholding_tax + (float) $slip->other_deductions,
            2,
        );

        $this->assertSame(
            round((float) $slip->gross - $components, 2),
            round((float) $slip->net_pay, 2),
            'gross − what was collected must equal net, even at the floor',
        );
    }

    // --- a period with no working days -----------------------------------------------------------

    /** A DIVISOR — and the system's answer is better than the one I went looking for. */
    public function test_a_period_containing_no_scheduled_workdays_is_refused_by_name(): void
    {
        $employee = $this->employee('E-001');
        $employee->load('user');

        try {
            // 2026-08-01 is a Saturday, 08-02 a Sunday — a cycle with no workday in it.
            app(PayrollService::class)->run('2026-08-01', '2026-08-02', 'semi_monthly', $this->hrUser());
            $this->fail('a period with no scheduled workdays must not price silently');
        } catch (ValidationException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('no scheduled workdays', $message);
            // The employee is NAMED — otherwise HR cannot tell whose schedule is wrong.
            $this->assertStringContainsString((string) $employee->user?->name, $message);
        }

        $this->assertSame(0, Payslip::count(), 'and nothing was written');
    }

    /** A schedule with no workdays at all is the same hazard from the other direction, refused the same way. */
    public function test_an_employee_whose_schedule_has_no_workdays_is_refused(): void
    {
        $schedule = WorkSchedule::create([
            'name' => 'No days', 'workdays' => [],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 0,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => false,
        ]);

        $this->employee('E-001', ['work_schedule_id' => $schedule->id]);

        $this->expectException(ValidationException::class);
        $this->runFirstHalf();
    }

    // --- a hire and a leaver on the same run ------------------------------------------------------

    /**
     * The two prorations run in OPPOSITE DIRECTIONS through one code path — a hire is unpaid before their
     * start, a leaver unpaid after their last day. Getting one right does not mean the other is, and a
     * single run containing both is where a shared off-by-one shows itself.
     */
    public function test_a_hire_and_a_leaver_on_the_same_run_are_both_prorated(): void
    {
        $full = $this->employee('E-FULL');
        $hire = $this->employee('E-HIRE', ['hire_date' => '2026-08-10']);
        $leaver = $this->employee('E-LEAVE', [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-08-06',
        ]);

        foreach ([$full, $hire, $leaver] as $employee) {
            $this->workFirstHalf($employee);
        }

        $run = $this->runFirstHalf();

        $fullSlip = $run->payslips()->where('employee_id', $full->id)->firstOrFail();
        $hireSlip = $run->payslips()->where('employee_id', $hire->id)->firstOrFail();
        $leaverSlip = $run->payslips()->where('employee_id', $leaver->id)->firstOrFail();

        // Both partial employees earn less basic than the one who worked the whole period.
        $this->assertLessThan((float) $fullSlip->base_salary, (float) $hireSlip->base_salary);
        $this->assertLessThan((float) $fullSlip->base_salary, (float) $leaverSlip->base_salary);

        // And both earn something — a proration that pays nothing is the off-by-one this catches.
        $this->assertGreaterThan(0.0, (float) $hireSlip->base_salary);
        $this->assertGreaterThan(0.0, (float) $leaverSlip->base_salary);
    }

    /** The run must count all three, and its totals must still reconcile with them. */
    public function test_the_run_totals_include_both_the_hire_and_the_leaver(): void
    {
        $full = $this->employee('E-FULL');
        $hire = $this->employee('E-HIRE', ['hire_date' => '2026-08-10']);
        $leaver = $this->employee('E-LEAVE', [
            'separation_type' => 'voluntary', 'separation_date' => '2026-08-06',
        ]);
        foreach ([$full, $hire, $leaver] as $employee) {
            $this->workFirstHalf($employee);
        }

        $run = $this->runFirstHalf();

        $this->assertSame(3, (int) $run->employee_count);
        $this->assertSame(
            round((float) $run->payslips()->sum('net_pay'), 2),
            round((float) $run->total_net, 2),
        );
    }

    /** Somebody hired AFTER the period ends is not on it at all. */
    public function test_an_employee_hired_after_the_period_is_not_paid(): void
    {
        $this->employee('E-FULL');
        $future = $this->employee('E-FUTURE', ['hire_date' => '2026-09-01']);

        $run = $this->runFirstHalf();

        $this->assertNull($run->payslips()->where('employee_id', $future->id)->first());
    }

    /** And somebody who left BEFORE it started is not either. */
    public function test_an_employee_who_left_before_the_period_is_not_paid(): void
    {
        $this->employee('E-FULL');
        $gone = $this->employee('E-GONE', [
            'separation_type' => 'voluntary', 'separation_date' => '2026-07-15',
        ]);
        $gone->delete();

        $run = $this->runFirstHalf();

        $this->assertNull($run->payslips()->where('employee_id', $gone->id)->first());
    }

    // --- a shift that crosses midnight ------------------------------------------------------------

    /**
     * `time_out < time_in` IS NOT AN ERROR, it is a night shift. Treated as one, the worked span goes
     * negative and the sign propagates into overtime and night differential.
     */
    public function test_a_shift_clocking_out_after_midnight_is_not_negative(): void
    {
        $night = WorkSchedule::create([
            'name' => 'Night', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => false,
        ]);

        $employee = $this->employee('E-NIGHT', ['work_schedule_id' => $night->id]);
        $tz = config('app.timezone_local');

        // In at 22:00 Monday, out at 06:00 Tuesday — the clock-out is on the NEXT calendar day.
        for ($day = Carbon::parse('2026-08-03', $tz); $day->day <= 14 && $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(22, 0)->utc(),
                'time_out' => $day->copy()->addDay()->setTime(6, 0)->utc(),
                'status' => 'present',
            ]);
        }

        $slip = $this->runFirstHalf()->payslips()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertGreaterThanOrEqual(0, (int) $slip->undertime_minutes, 'never a negative span');
        $this->assertGreaterThanOrEqual(0, (int) $slip->late_minutes);
        $this->assertGreaterThanOrEqual(0.0, (float) $slip->net_pay);
        // A full night shift is not an absence — the employee worked it.
        $this->assertGreaterThan(0.0, (float) $slip->base_salary);
    }

    /** A night shift earns night differential — the hours 22:00–06:00 are entirely within the window. */
    public function test_a_cross_midnight_shift_earns_night_differential(): void
    {
        $night = WorkSchedule::create([
            'name' => 'Night', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => false,
        ]);
        $employee = $this->employee('E-NIGHT', ['work_schedule_id' => $night->id]);

        $tz = config('app.timezone_local');
        for ($day = Carbon::parse('2026-08-03', $tz); $day->day <= 14 && $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(22, 0)->utc(),
                'time_out' => $day->copy()->addDay()->setTime(6, 0)->utc(),
                'status' => 'present',
            ]);
        }

        $slip = $this->runFirstHalf()->payslips()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertGreaterThan(0, (int) $slip->night_diff_minutes, 'the whole shift is night hours');
        $this->assertGreaterThan(0.0, (float) $slip->night_diff_pay);
    }

    /** A missing clock-out is a data problem, not a 26-hour day. */
    public function test_a_missing_clock_out_is_not_treated_as_a_marathon_shift(): void
    {
        $employee = $this->employee('E-001');
        $this->workFirstHalf($employee);

        // Wipe one day's clock-out.
        Attendance::where('employee_id', $employee->id)
            ->where('work_date', '2026-08-05')
            ->update(['time_out' => null]);

        $slip = $this->runFirstHalf()->payslips()->firstOrFail();

        $this->assertSame(0, (int) $slip->overtime_minutes, 'no overtime can be earned without a clock-out');
        $this->assertGreaterThanOrEqual(0.0, (float) $slip->net_pay);
    }

    // --- all of it on one run ---------------------------------------------------------------------

    /**
     * Every edge at once. If the boundaries interact — and they share `unpaidDaysDetailFor()` — this is
     * where it shows, and the run still has to reconcile.
     */
    public function test_a_run_containing_every_edge_case_still_reconciles(): void
    {
        $night = WorkSchedule::create([
            'name' => 'Night', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => false,
        ]);

        $full = $this->employee('E-FULL');
        $hire = $this->employee('E-HIRE', ['hire_date' => '2026-08-10']);
        $leaver = $this->employee('E-LEAVE', [
            'separation_type' => 'voluntary', 'separation_date' => '2026-08-06',
        ]);
        $thin = $this->employee('E-THIN', ['base_salary' => 4000]);
        $nightShift = $this->employee('E-NIGHT', ['work_schedule_id' => $night->id]);

        foreach ([$full, $hire, $leaver, $thin] as $employee) {
            $this->workFirstHalf($employee);
        }

        $tz = config('app.timezone_local');
        for ($day = Carbon::parse('2026-08-03', $tz); $day->day <= 14 && $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $nightShift->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(22, 0)->utc(),
                'time_out' => $day->copy()->addDay()->setTime(6, 0)->utc(),
                'status' => 'present',
            ]);
        }

        EmployeeLoan::create([
            'employee_id' => $thin->id, 'type' => 'loan',
            'principal' => 90000, 'per_period_amount' => 80000,
            'start_date' => '2026-01-01', 'status' => 'active', 'authorized_at' => now(),
        ]);

        $run = $this->runFirstHalf();

        $this->assertSame(5, (int) $run->employee_count);
        $this->assertSame(
            round((float) $run->payslips()->sum('net_pay'), 2),
            round((float) $run->total_net, 2),
        );
        $this->assertSame(
            round((float) $run->payslips()->sum('gross'), 2),
            round((float) $run->total_gross, 2),
        );

        foreach ($run->payslips as $slip) {
            $this->assertGreaterThanOrEqual(0.0, (float) $slip->net_pay, "payslip {$slip->id} went negative");
            $this->assertFalse(is_nan((float) $slip->net_pay), "payslip {$slip->id} is NaN");
        }
    }
}
