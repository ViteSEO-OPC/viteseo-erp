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

    /** @return array{overtime_minutes:int, night_diff_minutes:int, night_diff_pay:float} */
    private function payrollResult(): array
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payslip = $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();

        $result = [
            'overtime_minutes' => (int) $payslip->overtime_minutes,
            'night_diff_minutes' => (int) $payslip->night_diff_minutes,
            'night_diff_pay' => (float) $payslip->night_diff_pay,
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
     * MISSING TIME-OUT. An open punch proves nothing about when the employee left, so it earns
     * no differential and no overtime — and an approved request cannot conjure either. Both
     * appear the moment HR supplies the missing punch, which is the whole correction workflow.
     */
    public function test_a_missing_time_out_earns_no_night_differential_or_overtime(): void
    {
        $this->schedule('22:00', '06:00');
        $row = $this->worked('2026-08-03', '22:00', null);
        $this->approved('2026-08-03', '06:00', '08:00');

        $open = $this->payrollResult();
        $this->assertSame(0, $open['night_diff_minutes'], 'an open punch must not pay ND');
        $this->assertSame(0, $open['overtime_minutes'], 'an open punch must not pay OT');
        $this->assertSame(0.0, $open['night_diff_pay']);

        $row->update([
            'time_out' => Carbon::parse('2026-08-04 08:00', config('app.timezone_local'))->utc(),
        ]);

        $corrected = $this->payrollResult();
        $this->assertSame(480, $corrected['night_diff_minutes'], '22:00–06:00 is all night');
        $this->assertSame(120, $corrected['overtime_minutes'], '06:00–08:00 is the approved tail');
        $this->assertGreaterThan(0.0, $corrected['night_diff_pay']);
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

        $this->assertSame(480, $result['night_diff_minutes']);
        $this->assertSame(0, $result['overtime_minutes'], 'the first 8 rest-day hours are premium, not OT');
        $this->assertGreaterThan(0.0, $result['night_diff_pay']);
    }
}
