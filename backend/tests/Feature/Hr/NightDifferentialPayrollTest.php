<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\OvertimeRequestService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The night differential where it meets the rest of the day's pay: unpaid meals, holidays,
 * rest days and missing punches.
 *
 * The three overnight/ND fixes are covered from the clock side (NightShiftClockTest), the
 * approval side (ApprovedOvertimePunchOverlapTest) and the configuration side
 * (ConfigurableNightDifferentialTest). What none of them exercise is how ND behaves once
 * another pay rule is acting on the SAME minutes — which is where a wrong answer is both
 * most likely and hardest to spot on a payslip.
 *
 * All dates sit inside 2026-08-01…15 (Mon 3rd, Tue 4th, Sun 2nd), and the clock is parked
 * after the cycle so the run is always pricing a closed period.
 */
class NightDifferentialPayrollTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 0,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);

        // A divisor-based daily rate so the hourly figure does not move when a test declares a
        // holiday — the compounding assertion below compares two runs and needs a stable base.
        app(AttendancePolicyService::class)->get()->update([
            'overtime_mode' => 'approved_only',
            'daily_rate_basis' => 'fixed_divisor',
            'annual_divisor' => 261,
            'hours_per_day_divisor' => 8,
        ]);

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $department->id,
            'employee_no' => 'E-ND-100',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    /**
     * Repoint the default schedule. The employee has no assignment, so the resolver falls back
     * to this row and every service reads it on the next resolution.
     */
    private function schedule(string $start, string $end): void
    {
        WorkSchedule::firstOrFail()->update(['start_time' => $start, 'end_time' => $end]);
    }

    /** One attendance row. A `null` $timeOut is a forgotten clock-out; times roll past midnight. */
    private function worked(string $date, string $timeIn, ?string $timeOut, int $lunch = 0): Attendance
    {
        $tz = config('app.timezone_local');
        $clockIn = Carbon::parse("{$date} {$timeIn}", $tz);
        $clockOut = $timeOut === null ? null : Carbon::parse("{$date} {$timeOut}", $tz);
        if ($clockOut !== null && $clockOut->lte($clockIn)) {
            $clockOut->addDay();
        }

        return Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $clockIn->utc(),
            'time_out' => $clockOut?->utc(),
            'lunch_minutes' => $lunch,
            'status' => 'present',
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function approved(string $date, string $from, string $to): void
    {
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_from' => $from,
            'time_to' => $to,
            'hours' => OvertimeRequestService::hoursBetween($from, $to),
            'reason' => 'Month-end close',
            'status' => 'approved',
        ]);
    }

    private function holiday(string $date, string $type): void
    {
        $set = HolidaySet::firstOrCreate(
            ['country_code' => 'PH', 'year' => 2026],
            ['source' => 'manual'],
        );

        Holiday::create([
            'holiday_set_id' => $set->id,
            'name' => 'Test Holiday',
            'date' => $date,
            'type' => $type,
            'source' => 'manual',
        ]);
    }

    /** @return array{overtime_minutes:int, night_diff_minutes:int, night_diff_pay:float, basic_pay:float} */
    private function payrollResult(): array
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payslip = $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();

        $result = [
            'overtime_minutes' => (int) $payslip->overtime_minutes,
            'night_diff_minutes' => (int) $payslip->night_diff_minutes,
            'night_diff_pay' => (float) $payslip->night_diff_pay,
            'basic_pay' => (float) $payslip->basic_pay,
        ];

        // Re-runnable: the next call in the same test prices the same cycle from scratch.
        $run->payslips()->forceDelete();
        $run->forceDelete();

        return $result;
    }

    private function nightMinutes(string $date): int
    {
        return app(AttendanceService::class)
            ->nightMinutesFor($this->employee, $date, $date)[$date] ?? 0;
    }

    /**
     * UNPAID BREAK, graveyard. Every minute of a 22:00–06:00 shift is night, so a recorded meal
     * has no daytime to hide in and must come straight off the differential.
     */
    public function test_a_recorded_meal_on_a_graveyard_shift_reduces_the_night_minutes(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00', lunch: 60);

        $this->assertSame(420, $this->nightMinutes('2026-08-03'));
        $this->assertSame(420, $this->payrollResult()['night_diff_minutes']);
    }

    /**
     * UNPAID BREAK, evening. The mirror case: eight daytime hours can absorb the whole meal, so
     * the hour inside the window is paid in full. Charging it here would under-pay every
     * late-shift worker who takes a 15:00 lunch.
     */
    public function test_an_evening_shifts_daytime_hours_absorb_the_meal(): void
    {
        $this->schedule('14:00', '23:30');
        $this->worked('2026-08-03', '14:00', '23:00', lunch: 60);

        $this->assertSame(60, $this->nightMinutes('2026-08-03'));
        $this->assertSame(60, $this->payrollResult()['night_diff_minutes']);
    }

    /**
     * MISSING TIME-OUT (item 5). An abandoned shift resolves to the employee's own scheduled end,
     * so the graveyard worker is paid the full ordinary shift INCLUDING the differential — the
     * thing an open punch used to forfeit entirely.
     *
     * Overtime stays zero, structurally: overtime is `out − scheduled end`, and the resolved
     * `out` IS the scheduled end. The approved 06:00–08:00 request deliberately does not extend
     * it — approval is permission, not proof — and only a real punch releases it.
     */
    public function test_an_abandoned_shift_pays_the_scheduled_shift_with_no_overtime(): void
    {
        $this->schedule('22:00', '06:00');
        $row = $this->worked('2026-08-03', '22:00', null);
        $this->approved('2026-08-03', '06:00', '08:00');

        $abandoned = $this->payrollResult();
        // 420, not 480: the scheduled 60-minute meal is carved out (item 9). A graveyard shift's
        // daytime portion is zero, so nothing can absorb the break, and the differential is
        // paid on exactly the day's expected paid minutes (480 span − 60 unpaid meal).
        $this->assertSame(420, $abandoned['night_diff_minutes'], '22:00–06:00 resolves to a full paid night');
        $this->assertSame(0, $abandoned['overtime_minutes'], 'an approval cannot pay unclocked overtime');
        $this->assertGreaterThan(0.0, $abandoned['night_diff_pay']);

        // HR supplies the real punch: now the approved tail has evidence behind it and pays.
        $row->update([
            'time_out' => Carbon::parse('2026-08-04 08:00', config('app.timezone_local'))->utc(),
        ]);

        $corrected = $this->payrollResult();
        $this->assertSame(480, $corrected['night_diff_minutes']);
        $this->assertSame(120, $corrected['overtime_minutes'], '06:00–08:00 is the approved tail');
    }

    /**
     * THE ALLOWANCE IS THE SAFETY. Someone who is still on shift right now has an open punch too,
     * and resolving theirs would pay hours they have not worked yet. Nothing is owed until the
     * scheduled end plus the allowance has actually passed — this is what makes a mid-cycle
     * payroll preview safe to run at any hour of the day.
     */
    public function test_a_shift_still_inside_the_allowance_is_not_resolved(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', null);

        // 07:00 on the 4th: the 06:00 shift end has passed, but the 3-hour allowance has not.
        $this->travelTo(Carbon::parse('2026-08-04 07:00', config('app.timezone_local')));
        $this->assertSame(0, $this->nightMinutes('2026-08-03'), 'they may simply still be working');

        // 09:30 — past the cutoff, so the shift is now abandoned and resolves.
        $this->travelTo(Carbon::parse('2026-08-04 09:30', config('app.timezone_local')));
        // 420, not 480: the scheduled 60-minute meal is carved out (item 9). A graveyard shift's
        // daytime portion is zero, so nothing can absorb the break, and the differential is
        // paid on exactly the day's expected paid minutes (480 span − 60 unpaid meal).
        $this->assertSame(420, $this->nightMinutes('2026-08-03'));
    }

    /**
     * AUTO-OT MODE IS THE DANGEROUS ONE. Under `approved_only` a forgotten punch was never going
     * to pay overtime anyway; in auto mode overtime is whatever is past the shift end, so this is
     * the mode where resolving to the wrong time would quietly pay hours nobody worked.
     */
    public function test_an_abandoned_shift_pays_no_overtime_in_auto_mode(): void
    {
        app(AttendancePolicyService::class)->get()->update([
            'overtime_mode' => 'auto_after_threshold',
            'overtime_threshold_minutes' => 0,
            'auto_overtime_daily_cap_minutes' => 0, // uncapped — nothing else is holding it back
        ]);
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', null);

        $result = $this->payrollResult();

        $this->assertSame(0, $result['overtime_minutes'], 'auto mode must not pay an unclocked overrun');
        // 420: a pure graveyard shift has no daytime portion to absorb the scheduled 60-minute
        // meal, so the differential is paid on the day's expected paid minutes (item 9).
        $this->assertSame(420, $result['night_diff_minutes']);
        $this->assertSame([], app(AttendanceService::class)
            ->overtimeCapacityFor($this->employee, '2026-08-03', '2026-08-03'));
    }

    /** A plain day shift resolves the same way: the full day, no night minutes, no overtime. */
    public function test_an_abandoned_day_shift_is_paid_exactly_like_a_completed_one(): void
    {
        $this->worked('2026-08-03', '08:00', null); // default 08:00–17:00 schedule
        $abandoned = $this->payrollResult();

        Attendance::query()->forceDelete(); // soft-delete would keep tripping the unique index
        $this->worked('2026-08-03', '08:00', '17:00');
        $completed = $this->payrollResult();

        $this->assertSame(0, $abandoned['night_diff_minutes']);
        $this->assertSame(0, $abandoned['overtime_minutes']);
        // The point of resolving to the scheduled end: the day is not docked as undertime.
        $this->assertSame($completed['basic_pay'], $abandoned['basic_pay']);
    }

    /** An employee with no schedule at all has nothing to resolve against, and must not blow up. */
    public function test_an_employee_with_no_schedule_is_left_alone(): void
    {
        $this->worked('2026-08-03', '08:00', null);
        WorkSchedule::query()->delete(); // bypasses the service guard on purpose

        $this->assertSame(0, $this->nightMinutes('2026-08-03'));
        $this->assertSame(0, $this->payrollResult()['overtime_minutes']);
    }

    /** A rest day has no shift to be the end of, so an open punch there is never resolved. */
    public function test_an_abandoned_rest_day_shift_is_left_for_hr(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-02', '22:00', null); // Sunday

        $this->assertSame(0, $this->nightMinutes('2026-08-02'));
        $this->assertSame(0, $this->payrollResult()['night_diff_minutes']);
    }

    /**
     * HOLIDAY COMPOUNDING. Night minutes that are ALSO overtime take the premium on the overtime
     * rate, so the same 60 minutes are worth more on a regular holiday (260%) than on an ordinary
     * day (125%). Comparing two runs of one punch pattern isolates that ratio from the daily rate.
     */
    public function test_night_overtime_on_a_regular_holiday_compounds_at_the_holiday_rate(): void
    {
        // Tue 4 Aug, 08:00 → 23:00 with the whole evening approved. 22:00–23:00 is night AND overtime.
        $this->worked('2026-08-04', '08:00', '23:00', lunch: 60);
        $this->approved('2026-08-04', '17:00', '23:00');

        $ordinary = $this->payrollResult();
        $this->assertSame(60, $ordinary['night_diff_minutes']);
        $this->assertSame(360, $ordinary['overtime_minutes']);
        $this->assertGreaterThan(0.0, $ordinary['night_diff_pay']);

        $this->holiday('2026-08-04', 'regular');
        $regularHoliday = $this->payrollResult();

        $this->assertSame(60, $regularHoliday['night_diff_minutes'], 'the same minutes are night');
        $this->assertSame(360, $regularHoliday['overtime_minutes'], 'and the same minutes are overtime');
        $this->assertEqualsWithDelta(
            $ordinary['night_diff_pay'] * (260 / 125),
            $regularHoliday['night_diff_pay'],
            0.02,
            'night OT on a regular holiday must compound onto the 260% rate, not the 125% one',
        );
    }

    /**
     * REST DAY, graveyard. A Sunday 22:00–06:00 shift earns the differential on all eight hours,
     * while the hours themselves are rest-day premium rather than overtime — the first eight hours
     * of a worked rest day are never both.
     */
    public function test_a_graveyard_rest_day_shift_earns_night_pay_but_no_overtime(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-02', '22:00', '06:00'); // Sunday — not in workdays [1..5]
        $this->approved('2026-08-02', '22:00', '06:00');

        $result = $this->payrollResult();

        // 420: a pure graveyard shift has no daytime portion to absorb the scheduled 60-minute
        // meal, so the differential is paid on the day's expected paid minutes (item 9).
        $this->assertSame(420, $result['night_diff_minutes']);
        $this->assertSame(0, $result['overtime_minutes'], 'the first 8 rest-day hours are premium, not OT');
        $this->assertGreaterThan(0.0, $result['night_diff_pay']);
    }
}
