<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\RestDayRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Clocking in before your shift starts — `early_clock_in_mode`.
 *
 *   allow  today's behaviour: recorded at the moment they pressed it
 *   defer  accepted, but re-stamped to the shift's start time. Arrive at 07:30 for an
 *          08:00 shift and you are recorded as timing in at 08:00 — nobody waits at the
 *          door, and nobody is paid for the early half hour.
 *   block  refused until the shift has started
 *
 * The rule only applies when there is a real shift to be early FOR. On a non-workday, or
 * with no scheduled start, every mode records the punch as-is: FALLBACK_START is a guess,
 * and a guess must never silently move somebody's time in.
 */
class EarlyClockInModeTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** Shift is 09:00–18:00 with 15 minutes' grace. */
    private const EARLY = '2026-07-01 07:30';

    private const SHIFT_START = '2026-07-01 09:00';

    private const AFTER_START = '2026-07-01 09:05';

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::create([
            'name' => 'Day', 'workdays' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 15, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function atManila(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, config('app.timezone_local')));
    }

    private function mode(string $mode): void
    {
        app(AttendancePolicyService::class)->update(['early_clock_in_mode' => $mode]);
    }

    /** Re-resolved after each policy change — the policy row is memoized per instance. */
    private function clock(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    /** The punch's Manila wall-clock time, which is what everyone actually reasons about. */
    private function manilaTimeOf(\Illuminate\Support\Carbon $utc): string
    {
        return $utc->timezone(config('app.timezone_local'))->format('H:i');
    }

    // --- allow -----------------------------------------------------------------------

    public function test_allow_records_the_punch_at_the_moment_it_was_pressed(): void
    {
        $this->mode('allow');
        $this->atManila(self::EARLY);

        $att = $this->clock()->clockIn($this->employee);

        $this->assertSame('07:30', $this->manilaTimeOf($att->time_in));
        $this->assertSame('present', $att->status);
    }

    // --- block -----------------------------------------------------------------------

    public function test_block_refuses_an_early_punch(): void
    {
        $this->mode('block');
        $this->atManila(self::EARLY);

        $this->expectException(ValidationException::class);
        $this->clock()->clockIn($this->employee);
    }

    public function test_block_accepts_the_punch_once_the_shift_has_started(): void
    {
        $this->mode('block');
        $this->atManila(self::AFTER_START);

        $att = $this->clock()->clockIn($this->employee);

        $this->assertSame('09:05', $this->manilaTimeOf($att->time_in));
    }

    // --- defer -----------------------------------------------------------------------

    public function test_defer_accepts_an_early_punch_and_stamps_it_at_the_shift_start(): void
    {
        $this->mode('defer');
        $this->atManila(self::EARLY);

        $att = $this->clock()->clockIn($this->employee);

        $this->assertSame('09:00', $this->manilaTimeOf($att->time_in), 'Pressed 07:30, must count from 09:00.');
        $this->assertSame('2026-07-01', $att->work_date->toDateString());
    }

    /** A deferred punch is stamped exactly ON the start time, so it can never be late. */
    public function test_a_deferred_punch_is_never_late(): void
    {
        $this->mode('defer');
        $this->atManila(self::EARLY);

        $this->assertSame('present', $this->clock()->clockIn($this->employee)->status);
    }

    public function test_defer_leaves_an_on_time_punch_alone(): void
    {
        $this->mode('defer');
        $this->atManila(self::AFTER_START);

        $att = $this->clock()->clockIn($this->employee);

        $this->assertSame('09:05', $this->manilaTimeOf($att->time_in), 'Not early — nothing to defer.');
    }

    public function test_defer_leaves_a_late_punch_alone_and_still_marks_it_late(): void
    {
        $this->mode('defer');
        $this->atManila('2026-07-01 10:30');

        $att = $this->clock()->clockIn($this->employee);

        $this->assertSame('10:30', $this->manilaTimeOf($att->time_in));
        $this->assertSame('late', $att->status);
    }

    /**
     * No shift that day ⇒ nothing to be early for, so the punch is recorded as-is. Moving
     * it to a start time that doesn't exist would be inventing data.
     *
     * RD-2 changed how the punch GETS there — a rest day now goes through an HR approval
     * rather than straight into attendance — but not this rule: the stamped time is the
     * moment the employee pressed the button, whatever the early-clock-in mode says.
     */
    public function test_defer_does_not_touch_an_approved_rest_day_punch(): void
    {
        WorkSchedule::query()->update(['workdays' => json_encode([1, 2, 3, 4, 5])]);
        $this->mode('defer');
        $this->atManila('2026-07-04 07:30'); // a Saturday

        $request = app(RestDayRequestService::class)->create($this->employee, '2026-07-04');
        // Approved two hours later, to prove the stamp follows the press and not the decision.
        $this->atManila('2026-07-04 09:30');
        app(RestDayRequestService::class)->approve($request, $this->hrUser());

        $att = Attendance::where('employee_id', $this->employee->id)
            ->where('work_date', '2026-07-04')
            ->firstOrFail();

        $this->assertSame('07:30', $this->manilaTimeOf($att->time_in));
    }

    /** A rest day is not the employee's to start — the punch becomes a request instead. */
    public function test_clocking_in_on_a_non_workday_is_refused(): void
    {
        WorkSchedule::query()->update(['workdays' => json_encode([1, 2, 3, 4, 5])]);
        $this->mode('defer');
        $this->atManila('2026-07-04 07:30'); // a Saturday

        $this->expectException(ValidationException::class);
        $this->clock()->clockIn($this->employee);
    }

    // --- what the panel is told ---------------------------------------------------------

    public function test_the_window_reports_the_mode_and_when_a_punch_would_count(): void
    {
        $this->mode('defer');
        $this->atManila(self::EARLY);

        $window = $this->clock()->clockInWindowFor($this->employee);

        $this->assertSame('defer', $window['mode']);
        $this->assertSame('09:00', $window['defers_to'], 'The panel must be able to say when it will count.');
        $this->assertNull($window['opens_at'], 'Defer never holds the button back.');
    }

    public function test_the_window_stops_advertising_a_deferral_once_the_shift_starts(): void
    {
        $this->mode('defer');
        $this->atManila(self::AFTER_START);

        $this->assertNull($this->clock()->clockInWindowFor($this->employee)['defers_to']);
    }

    public function test_block_still_reports_when_the_button_opens(): void
    {
        $this->mode('block');
        $this->atManila(self::EARLY);

        $window = $this->clock()->clockInWindowFor($this->employee);

        $this->assertSame('block', $window['mode']);
        $this->assertSame('09:00', $window['opens_at']);
        $this->assertNull($window['defers_to']);
    }

    // --- the queued window --------------------------------------------------------------

    /**
     * Between pressing the button and the shift starting, the employee is NOT on the clock.
     * Clocking out then would put time_out before time_in and silently produce a zero-hour
     * day, so it is refused with an explanation instead.
     */
    public function test_clocking_out_while_the_punch_is_still_queued_is_refused(): void
    {
        $this->mode('defer');
        $this->atManila(self::EARLY);
        $this->clock()->clockIn($this->employee);

        $this->atManila('2026-07-01 07:45');
        $this->expectException(ValidationException::class);
        $this->clock()->clockOut($this->employee);
    }

    public function test_starting_a_break_while_the_punch_is_still_queued_is_refused(): void
    {
        $this->mode('defer');
        $this->atManila(self::EARLY);
        $this->clock()->clockIn($this->employee);

        $this->atManila('2026-07-01 07:45');
        $this->expectException(ValidationException::class);
        $this->clock()->toggleBreak($this->employee, 'lunch');
    }

    /** Once the shift has actually started, everything behaves completely normally. */
    public function test_the_day_proceeds_normally_once_the_shift_starts(): void
    {
        $this->mode('defer');
        $this->atManila(self::EARLY);
        $this->clock()->clockIn($this->employee);

        $this->atManila('2026-07-01 12:00');
        $this->clock()->toggleBreak($this->employee, 'lunch');
        $this->atManila('2026-07-01 13:00');
        $this->clock()->toggleBreak($this->employee, 'lunch');

        $this->atManila('2026-07-01 18:00');
        $att = $this->clock()->clockOut($this->employee);

        $this->assertNotNull($att->time_out);
        // 09:00 → 18:00 is 9h gross, less the 60-minute lunch taken.
        $this->assertEqualsWithDelta(8.0, (float) $att->hours_worked, 0.01);
    }

    /**
     * The deferred stamp must survive the trip through the database as 09:00 MANILA.
     * `time_in` is stored UTC, and handing Eloquent a Manila-tz Carbon without converting
     * would write the wall-clock digits into a UTC column — landing 8 hours out.
     */
    public function test_the_deferred_stamp_round_trips_through_the_database(): void
    {
        $this->mode('defer');
        $this->atManila(self::EARLY);

        $id = $this->clock()->clockIn($this->employee)->id;

        $fresh = \App\Modules\Hr\Models\Attendance::findOrFail($id);
        $this->assertSame('09:00', $this->manilaTimeOf($fresh->time_in));
        $this->assertSame('01:00', $fresh->time_in->utc()->format('H:i'), 'Manila 09:00 is 01:00 UTC.');
    }
}
