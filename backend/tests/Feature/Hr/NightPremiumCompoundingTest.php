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

/** Item 9 — the night differential where it meets the day's own rate. */
class NightPremiumCompoundingTest extends HrTestCase
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

        // A divisor-based daily rate so declaring a holiday cannot move the hourly figure itself —
        // the ratios below would otherwise be measuring the rate basis rather than the multiplier.
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
            'employee_no' => 'E-NPC-100',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    private function schedule(string $start, string $end): void
    {
        WorkSchedule::firstOrFail()->update(['start_time' => $start, 'end_time' => $end]);
    }

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

    /** An exact-window approval — it can prove which of its minutes were night. */
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

    /** A LEGACY approval: hours only, no window. Pre-dates the time_from/time_to columns. */
    private function approvedHoursOnly(string $date, float $hours): void
    {
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_from' => null,
            'time_to' => null,
            'hours' => $hours,
            'reason' => 'Legacy approval',
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

        $run->payslips()->forceDelete();
        $run->forceDelete();

        return $result;
    }

    // --- 9a: plain night hours compound onto the day's rate ---------------------------------------

    /**
     * THE HEADLINE FIX. Mon 3 Aug, a graveyard shift with no overtime at all — every night minute
     * is "plain". Declaring the day a regular holiday must double the differential, because the
     * rate those minutes are worth doubles.
     */
    public function test_plain_night_hours_on_a_regular_holiday_pay_at_the_holiday_rate(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00');

        $ordinary = $this->payrollResult();
        $this->assertSame(420, $ordinary['night_diff_minutes']);
        $this->assertSame(0, $ordinary['overtime_minutes'], 'no overtime — these are all plain night minutes');
        $this->assertGreaterThan(0.0, $ordinary['night_diff_pay']);

        $this->holiday('2026-08-03', 'regular');
        $holiday = $this->payrollResult();

        $this->assertSame(420, $holiday['night_diff_minutes'], 'the same minutes are night');
        $this->assertEqualsWithDelta(
            $ordinary['night_diff_pay'] * (200 / 100),
            $holiday['night_diff_pay'],
            0.02,
            'a night hour on a regular holiday is 10% of the 200% rate, not of the base rate',
        );
    }

    public function test_plain_night_hours_on_a_special_non_working_day_pay_at_the_special_rate(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00');
        $ordinary = $this->payrollResult();

        $this->holiday('2026-08-03', 'special_non_working');
        $special = $this->payrollResult();

        $this->assertEqualsWithDelta(
            $ordinary['night_diff_pay'] * (130 / 100),
            $special['night_diff_pay'],
            0.02,
        );
    }

    /**
     * A rest day carries its own premium too, and it is reached by a different branch — there is
     * no holiday row at all, just a date outside the schedule's workdays.
     */
    public function test_plain_night_hours_on_a_rest_day_pay_at_the_rest_day_rate(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00'); // Monday — a workday
        $workday = $this->payrollResult();

        Attendance::query()->forceDelete();
        $this->worked('2026-08-02', '22:00', '06:00'); // Sunday — outside workdays [1..5]
        $restDay = $this->payrollResult();

        $this->assertSame(
            $workday['night_diff_minutes'],
            $restDay['night_diff_minutes'],
            'the same eight-hour shift, so the same night minutes',
        );
        $this->assertEqualsWithDelta(
            $workday['night_diff_pay'] * (130 / 100),
            $restDay['night_diff_pay'],
            0.02,
        );
    }

    /** A special WORKING day is an ordinary day by definition — no premium, so no compounding. */
    public function test_a_special_working_day_does_not_change_the_differential(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00');
        $ordinary = $this->payrollResult();

        $this->holiday('2026-08-03', 'special_working');
        $specialWorking = $this->payrollResult();

        $this->assertEqualsWithDelta($ordinary['night_diff_pay'], $specialWorking['night_diff_pay'], 0.01);
    }

    /** The multiplier is HR's editable column, not a literal — raising the rate moves the pay. */
    public function test_raising_the_holiday_rate_raises_the_differential_with_it(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00');
        $this->holiday('2026-08-03', 'regular');

        $atMinimum = $this->payrollResult();

        app(AttendancePolicyService::class)->get()->update(['premium_regular_holiday_pct' => 300]);
        $raised = $this->payrollResult();

        $this->assertEqualsWithDelta(
            $atMinimum['night_diff_pay'] * (300 / 200),
            $raised['night_diff_pay'],
            0.02,
        );
    }

    /** REGRESSION GUARD: an ordinary workday must be untouched by all of the above. */
    public function test_an_ordinary_workday_is_priced_exactly_as_before(): void
    {
        $this->schedule('14:00', '23:00');
        $this->worked('2026-08-03', '14:00', '23:00', lunch: 60);

        $result = $this->payrollResult();
        $policy = app(AttendancePolicyService::class)->get();

        // 22:00–23:00 is night; the 8-hour daytime portion absorbs the whole meal.
        $this->assertSame(60, $result['night_diff_minutes']);

        $hourly = (30000.0 * 12 / 261) / 8;
        $this->assertEqualsWithDelta(
            $hourly * ((int) $policy->night_diff_pct / 100),
            $result['night_diff_pay'],
            0.02,
            'one plain night hour on an ordinary day is still hourly × 10%',
        );
    }

    public function test_the_day_rate_multiplier_reads_the_policy(): void
    {
        $policy = app(AttendancePolicyService::class)->get();

        $this->assertSame(100, PayrollService::dayRateMultiplierPct(null, true, $policy));
        $this->assertSame(100, PayrollService::dayRateMultiplierPct('special_working', true, $policy));
        $this->assertSame(130, PayrollService::dayRateMultiplierPct(null, false, $policy), 'rest day');
        $this->assertSame(130, PayrollService::dayRateMultiplierPct('special_non_working', true, $policy));
        $this->assertSame(200, PayrollService::dayRateMultiplierPct('regular', true, $policy));
    }

    // --- 9b: an hours-only approval can prove a night portion --------------------------------------

    /**
     * A legacy approval has no window of its own, but the punch-backed overtime interval does, so
     * its hours can be placed inside it and their night overlap read off. Reporting night = 0 sent
     * those minutes down the plain path at hourly × 10% instead of hourly × 125% × 10%.
     */
    public function test_a_legacy_hours_only_approval_earns_the_compounded_night_rate(): void
    {
        // Evening shift 13:00–21:00; worked through to 01:00, so overtime is 21:00 → 01:00 and
        // 22:00 → 01:00 of that is night.
        $this->schedule('13:00', '21:00');
        $this->worked('2026-08-03', '13:00', '01:00', lunch: 60);
        $this->approvedHoursOnly('2026-08-03', 4);

        $legacy = $this->payrollResult();
        $this->assertSame(240, $legacy['overtime_minutes'], 'four approved hours');

        // The same four hours approved as an exact window must price identically — that IS the
        // contract: the two request shapes describe the same work.
        OvertimeRequest::query()->forceDelete();
        $this->approved('2026-08-03', '21:00', '01:00');
        $exact = $this->payrollResult();

        $this->assertSame($exact['overtime_minutes'], $legacy['overtime_minutes']);
        $this->assertEqualsWithDelta(
            $exact['night_diff_pay'],
            $legacy['night_diff_pay'],
            0.02,
            'an hours-only approval must not be paid less than the identical exact one',
        );
    }

    /**
     * PARTIAL legacy hours are allocated EARLIEST FIRST, because overtime accrues from the
     * scheduled end forward — "you may stay until 22:00", not "you may stay from midnight".
     */
    public function test_partial_legacy_hours_are_taken_from_the_start_of_the_overtime(): void
    {
        $this->schedule('13:00', '21:00');
        $this->worked('2026-08-03', '13:00', '01:00', lunch: 60);
        $this->approvedHoursOnly('2026-08-03', 1); // 21:00 → 22:00, entirely before the night band

        $svc = app(AttendanceService::class);
        $one = $svc->approvedOvertimeMinutesFor($this->employee, '2026-08-01', '2026-08-15');

        $this->assertSame(60, $one['2026-08-03']['minutes']);
        $this->assertSame(
            0,
            $one['2026-08-03']['night'],
            'the earliest approved hour is 21:00–22:00, which is not night; back-loading it to '
                .'midnight would pay 60 night minutes for the same one approved hour',
        );

        // Three hours reaches 21:00 → 00:00, of which 22:00 → 00:00 is night. This half is what
        // separates "allocated from the start" from "not allocated at all" — without it the case
        // above passes just as well when legacy hours claim no night whatsoever.
        OvertimeRequest::query()->forceDelete();
        $this->approvedHoursOnly('2026-08-03', 3);
        $three = $svc->approvedOvertimeMinutesFor($this->employee, '2026-08-01', '2026-08-15');

        $this->assertSame(180, $three['2026-08-03']['minutes']);
        $this->assertSame(120, $three['2026-08-03']['night']);
    }

    /** Legacy hours fill only what the exact windows left, and never count a minute twice. */
    public function test_legacy_hours_do_not_double_count_night_already_claimed_exactly(): void
    {
        $this->schedule('13:00', '21:00');
        $this->worked('2026-08-03', '13:00', '01:00', lunch: 60);
        $this->approved('2026-08-03', '23:00', '01:00');   // 120 exact, all night
        $this->approvedHoursOnly('2026-08-03', 10);         // more than the day can hold

        $map = app(AttendanceService::class)->approvedOvertimeMinutesFor(
            $this->employee,
            '2026-08-01',
            '2026-08-15',
        );

        // The clocked overtime interval is 21:00 → 01:00 = 240 minutes, and that is the ceiling.
        $this->assertSame(240, $map['2026-08-03']['minutes']);
        // Night inside it is 22:00 → 01:00 = 180. Never more, however many hours were approved.
        $this->assertSame(180, $map['2026-08-03']['night']);
    }

    // --- 9c: the unpaid meal is carved using the scheduled break ------------------------------------

    /**
     * The graveyard worker who never presses the lunch button. Their basic pay covers 7 hours
     * (480 span − 60 meal); the differential used to be paid on all 8 purely because the button
     * went unpressed, which made a payroll figure depend on a UI habit.
     */
    public function test_the_scheduled_meal_is_carved_even_when_nobody_pressed_lunch(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00', lunch: 0);

        $this->assertSame(
            ['2026-08-03' => 420],
            app(AttendanceService::class)->nightMinutesFor($this->employee, '2026-08-01', '2026-08-15'),
        );
    }

    /** Pressing the button changes nothing when it agrees with the schedule. */
    public function test_recording_the_scheduled_meal_gives_the_same_answer(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00', lunch: 60);

        $this->assertSame(
            ['2026-08-03' => 420],
            app(AttendanceService::class)->nightMinutesFor($this->employee, '2026-08-01', '2026-08-15'),
        );
    }

    /** A LONGER recorded meal is still carved in full — the schedule is a floor, not a cap. */
    public function test_a_longer_recorded_meal_is_carved_in_full(): void
    {
        $this->schedule('22:00', '06:00');
        $this->worked('2026-08-03', '22:00', '06:00', lunch: 90);

        $this->assertSame(
            ['2026-08-03' => 390],
            app(AttendanceService::class)->nightMinutesFor($this->employee, '2026-08-01', '2026-08-15'),
        );
    }

    /**
     * THE CONSERVATIVE HALF, unchanged: only the part of the meal the DAYTIME portion cannot
     * account for comes out of the night minutes. An evening shift with eight daytime hours loses
     * nothing, because the meal demonstrably fits before 22:00.
     */
    public function test_a_daytime_portion_still_absorbs_the_whole_meal(): void
    {
        $this->schedule('13:00', '22:30');
        $this->worked('2026-08-03', '13:00', '22:30', lunch: 0);

        $this->assertSame(
            ['2026-08-03' => 30],
            app(AttendanceService::class)->nightMinutesFor($this->employee, '2026-08-01', '2026-08-15'),
            '22:00–22:30 is night and the meal fits in the eight daytime hours',
        );
    }

    /** An employee with a break-free schedule loses nothing, whatever the shift. */
    public function test_a_schedule_with_no_unpaid_break_carves_nothing(): void
    {
        WorkSchedule::firstOrFail()->update([
            'start_time' => '22:00', 'end_time' => '06:00', 'unpaid_break_minutes' => 0,
        ]);
        $this->worked('2026-08-03', '22:00', '06:00', lunch: 0);

        $this->assertSame(
            ['2026-08-03' => 480],
            app(AttendanceService::class)->nightMinutesFor($this->employee, '2026-08-01', '2026-08-15'),
        );
    }
}
