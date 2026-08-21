<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * A half day is a charge for the half the employee was AWAY, not a flat toll on the date. Work
 * done inside the excused half buys it back minute for minute, priced over the declared day —
 * so an employee who worked their own morning is paid for it without anybody cancelling the
 * request, and the attendance record ends up being the source of truth it always claimed to be.
 */
class HalfDayWorkedThroughTest extends HrTestCase
{
    use RefreshDatabase;

    /** A Monday, safely in the past so no day classifies as "still to come". */
    private const DATE = '2026-03-02';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // 08:00–17:00 with an unpaid 12:00–13:00 meal: each half is 4h, the declared day is 8h.
        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00', 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);
    }

    private function approveHalfDay(string $period): void
    {
        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'period' => $period, 'reason' => 'errand', 'status' => 'approved',
        ]);
    }

    private function worked(string $in, ?string $out = '17:00'): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'time_in' => Carbon::parse(self::DATE . " {$in}", $tz)->utc(),
            'time_out' => $out === null ? null : Carbon::parse(self::DATE . " {$out}", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function price(): float
    {
        return app(AttendanceService::class)->unpaidDaysFor($this->employee->fresh(), self::DATE, self::DATE);
    }

    // --- the rule that must not move -------------------------------------------------------

    public function test_a_half_day_worked_exactly_as_approved_still_costs_exactly_half(): void
    {
        $this->approveHalfDay('AM'); // morning off — they owe 13:00–17:00, and work it
        $this->worked('13:00', '17:00');

        $this->assertEqualsWithDelta(0.5, $this->price(), 1e-9);
    }

    // --- the buy-back ----------------------------------------------------------------------

    public function test_working_into_the_half_off_discounts_the_charge_minute_for_minute(): void
    {
        $this->approveHalfDay('AM');
        $this->worked('11:00', '17:00'); // an hour of their own morning

        // 0.5 − 60 ÷ 480. They used to be charged the full half regardless.
        $this->assertEqualsWithDelta(0.5 - 60 / 480, $this->price(), 1e-9);
    }

    public function test_working_the_whole_excused_half_cancels_the_half_day_charge(): void
    {
        $this->approveHalfDay('AM');
        $this->worked('08:00', '17:00'); // the entire day, request or no request

        $this->assertEqualsWithDelta(0.0, $this->price(), 1e-9);
    }

    public function test_the_afternoon_half_works_the_same_way(): void
    {
        $this->approveHalfDay('PM'); // afternoon off — they owe 08:00–12:00 and stay till 15:00
        $this->worked('08:00', '15:00');

        // 13:00–15:00 is inside the half they had off; the 12:00–13:00 meal is not worked time.
        $this->assertEqualsWithDelta(0.5 - 120 / 480, $this->price(), 1e-9);
    }

    public function test_the_ledger_line_says_how_much_was_bought_back(): void
    {
        $this->approveHalfDay('AM');
        $this->worked('10:00', '17:00');

        $lines = app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee->fresh(), self::DATE, self::DATE)
            ->lines;
        $half = collect($lines)->firstWhere('kind', 'half_day');

        $this->assertSame(0.25, $half['days']);
        $this->assertStringContainsString('worked 2h 0m', $half['detail']);
    }

    // --- the limits ------------------------------------------------------------------------

    public function test_an_open_punch_buys_nothing_back(): void
    {
        $this->approveHalfDay('AM');
        $this->worked('08:00', null); // arrived early, never clocked out — proves nothing

        $this->assertEqualsWithDelta(0.5, $this->price(), 1e-9);
    }

    public function test_a_whole_day_off_worked_in_full_can_never_pay_more_than_the_day(): void
    {
        $this->approveHalfDay('AM');
        $this->approveHalfDay('PM'); // both halves filed off = the whole day
        $this->worked('08:00', '17:00');

        // 480 worked minutes against a 480-minute declared day: exactly cancelled, never negative.
        $this->assertEqualsWithDelta(0.0, $this->price(), 1e-9);
    }

    public function test_a_meal_sat_through_is_not_worked_time(): void
    {
        $this->approveHalfDay('AM');
        $this->approveHalfDay('PM');
        $this->worked('12:00', '13:00'); // the unpaid meal, and nothing else

        $this->assertEqualsWithDelta(1.0, $this->price(), 1e-9);
    }

    public function test_lateness_on_the_worked_half_is_still_charged_on_top(): void
    {
        AttendancePolicy::query()->update(['late_deduction_mode' => 'per_hour', 'late_minutes_from' => 'after_grace']);

        $this->approveHalfDay('AM');
        $this->worked('14:00', '17:00'); // an hour late for the 13:00 half, nothing bought back

        // 0.5 for the half + 45 min late (60 − the 15-min grace) ÷ 480.
        $this->assertEqualsWithDelta(0.5 + 45 / 480, $this->price(), 1e-9);
    }

    public function test_the_buy_back_widens_what_undertime_may_cost(): void
    {
        AttendancePolicy::query()->update(['undertime_deduction_mode' => 'per_hour']);

        $this->approveHalfDay('AM');
        $this->worked('08:00', '16:00'); // whole morning worked, an hour short of 17:00

        // The half is fully bought back, so the day is whole again — and a whole day is what the
        // 60 minutes of undertime is charged against.
        $this->assertEqualsWithDelta(60 / 480, $this->price(), 1e-9);
    }

    // --- half-day LEAVE, the other funding model -------------------------------------------

    public function test_unpaid_half_day_leave_is_bought_back_the_same_way(): void
    {
        $type = LeaveType::create(['name' => 'LWOP', 'code' => 'LWOP', 'is_paid' => false, 'is_unlimited' => true, 'default_days' => 0]);
        LeaveRequest::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'start_date' => self::DATE, 'end_date' => self::DATE, 'days' => 0.5,
            'is_half_day' => true, 'half_day_period' => 'AM',
            'reason' => 'x', 'status' => 'approved',
        ]);
        $this->worked('10:00', '17:00');

        $this->assertEqualsWithDelta(0.5 - 120 / 480, $this->price(), 1e-9);
    }

    public function test_paid_half_day_leave_worked_through_keeps_its_full_pay_and_its_spent_credit(): void
    {
        $type = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'is_paid' => true]);
        $balance = LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2026, 'entitled_days' => 10, 'used_days' => 0.5,
        ]);
        LeaveRequest::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'start_date' => self::DATE, 'end_date' => self::DATE, 'days' => 0.5,
            'is_half_day' => true, 'half_day_period' => 'AM',
            'reason' => 'x', 'status' => 'approved',
        ]);
        $this->worked('08:00', '17:00');

        // Already paid in full, so there is nothing for the buy-back to move — and returning the
        // half credit is a leave-accounting decision this deliberately does not take.
        $this->assertEqualsWithDelta(0.0, $this->price(), 1e-9);
        $this->assertEqualsWithDelta(0.5, (float) $balance->fresh()->used_days, 1e-9);
    }

    // --- the punch that makes all of the above possible ------------------------------------

    public function test_an_early_punch_on_a_half_day_is_not_re_stamped(): void
    {
        AttendancePolicy::query()->update(['early_clock_in_mode' => 'defer']);
        $this->approveHalfDay('AM');

        $this->travelTo(Carbon::parse(self::DATE . ' 08:00', config('app.timezone_local')));
        $row = app(AttendanceService::class)->clockIn($this->employee);

        // Deferring it to 13:00 would have erased the morning pay is about to credit.
        $this->assertSame('08:00', $row->time_in->setTimezone(config('app.timezone_local'))->format('H:i'));
        $this->assertSame('present', $row->status);
    }

    public function test_a_half_day_is_not_locked_out_by_strict_clock_in(): void
    {
        AttendancePolicy::query()->update(['early_clock_in_mode' => 'block']);
        $this->approveHalfDay('AM');

        $this->travelTo(Carbon::parse(self::DATE . ' 08:00', config('app.timezone_local')));
        $row = app(AttendanceService::class)->clockIn($this->employee);

        $this->assertSame('08:00', $row->time_in->setTimezone(config('app.timezone_local'))->format('H:i'));
    }

    public function test_an_ordinary_day_is_still_deferred(): void
    {
        AttendancePolicy::query()->update(['early_clock_in_mode' => 'defer']);

        $this->travelTo(Carbon::parse(self::DATE . ' 07:00', config('app.timezone_local')));
        $row = app(AttendanceService::class)->clockIn($this->employee);

        $this->assertSame('08:00', $row->time_in->setTimezone(config('app.timezone_local'))->format('H:i'));
    }
}
