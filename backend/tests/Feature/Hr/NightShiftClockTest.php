<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Http\Resources\WorkScheduleResource;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\ManagementAttendanceService;
use App\Modules\Hr\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Slice 3 (2026-07-28 audit): a graveyard-shift employee must be able to clock out.
 *
 * `work_date` is the Manila date the shift STARTED, but every clock action looked up
 * "today's" row. Clock in 22:00 on the 1st, try to clock out 06:00 on the 2nd, and the
 * service reported "You have not clocked in today" — so the whole night-differential /
 * past-midnight-overtime feature set could only ever be fed by an HR correction.
 *
 * Time is frozen per test so "crossing midnight" is deterministic.
 */
class NightShiftClockTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private AttendanceService $svc;

    /** A fixed Manila night: clock in here, clock out the next morning. */
    private const SHIFT_START = '2026-07-01 22:00';

    private const SHIFT_END = '2026-07-02 06:00';

    protected function setUp(): void
    {
        parent::setUp();

        $schedule = WorkSchedule::create([
            'name' => 'Graveyard', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);

        $this->svc = app(AttendanceService::class);
    }

    /** Freeze the clock at a Manila wall-clock moment. */
    private function atManila(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, config('app.timezone_local')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** THE BUG: clock in before midnight, clock out after it. */
    public function test_a_graveyard_shift_can_clock_out_after_midnight(): void
    {
        $this->atManila(self::SHIFT_START);
        $in = $this->svc->clockIn($this->employee);
        $this->assertSame('2026-07-01', $in->work_date->toDateString());

        $this->atManila(self::SHIFT_END);
        $out = $this->svc->clockOut($this->employee);

        // Closed on the START date's row, not a new one for the 2nd.
        $this->assertSame('2026-07-01', $out->work_date->toDateString());
        $this->assertNotNull($out->time_out);
        $this->assertSame(8.0, (float) $out->hours_worked);
        $this->assertSame(1, Attendance::count());
    }

    /** The night differential can now actually be earned through the UI. */
    public function test_the_closed_overnight_shift_earns_night_minutes(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);
        $this->atManila(self::SHIFT_END);
        $this->svc->clockOut($this->employee);

        $night = $this->svc->nightMinutesFor($this->employee, '2026-07-01', '2026-07-02');

        // 22:00 → 06:00 is entirely inside the 22:00–06:00 window — but the differential is paid
        // on WORK, and the scheduled 60-minute meal is not work (item 9). A graveyard shift has no
        // daytime portion to absorb the break, so all 60 come off: 480 span − 60 = 420, which is
        // exactly the schedule's own `expected_minutes`. Nobody pressed the lunch button here, and
        // that used to be the difference between 420 and 480.
        $this->assertSame(['2026-07-01' => 420], $night);
    }

    /** A completed scheduled graveyard shift ends on time; crossing midnight is not overtime. */
    public function test_a_normal_overnight_shift_has_no_overtime_capacity(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);
        $this->atManila(self::SHIFT_END);
        $this->svc->clockOut($this->employee);

        $capacity = $this->svc->overtimeCapacityFor($this->employee, '2026-07-01', '2026-07-01');

        $this->assertSame([], $capacity);
    }

    /** Auto mode uses the next-day shift end too; an on-time graveyard shift earns no OT. */
    public function test_a_normal_overnight_shift_has_no_automatic_overtime(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);
        $this->atManila(self::SHIFT_END);
        $this->svc->clockOut($this->employee);

        $overtime = $this->svc->autoOvertimeMinutesFor($this->employee, '2026-07-01', '2026-07-01');

        $this->assertSame([], $overtime);
    }

    /** Only work after the following morning's scheduled end is overtime. */
    public function test_overnight_overtime_starts_after_the_next_day_shift_end(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);
        $this->atManila('2026-07-02 08:00');
        $this->svc->clockOut($this->employee);

        $capacity = $this->svc->overtimeCapacityFor($this->employee, '2026-07-01', '2026-07-01');
        $automatic = $this->svc->autoOvertimeMinutesFor($this->employee, '2026-07-01', '2026-07-01');

        $this->assertSame(['2026-07-01' => 120], $capacity);
        $this->assertSame(120, $automatic['2026-07-01']['minutes']);
        $this->assertSame(0, $automatic['2026-07-01']['night']);
    }

    /** An eight-hour graveyard span with a one-hour unpaid meal is a seven-hour paid day. */
    public function test_an_overnight_schedule_reports_its_expected_paid_minutes(): void
    {
        $effective = app(ScheduleService::class)->effectiveFor(
            $this->employee,
            Carbon::parse('2026-07-01', config('app.timezone_local')),
        );
        $resource = WorkScheduleResource::make(WorkSchedule::firstOrFail())->resolve(new Request);

        $this->assertSame(420, $effective['expected_minutes']);
        $this->assertSame(420, $resource['expected_minutes']);
    }

    /** System-generated management attendance must also end on the following morning. */
    public function test_management_overnight_attendance_ends_on_the_next_day(): void
    {
        $this->employee->update(['is_management' => true]);

        app(ManagementAttendanceService::class)->generate('2026-07-01', '2026-07-01');

        $row = Attendance::firstOrFail();
        $this->assertTrue($row->time_out->gt($row->time_in));
        $this->assertSame(480, (int) $row->time_in->diffInMinutes($row->time_out));
        $this->assertSame(7.0, (float) $row->hours_worked);
    }

    /** Breaks work mid-shift after midnight too. */
    public function test_a_break_can_be_toggled_after_midnight(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);

        $this->atManila('2026-07-02 01:00');
        $this->svc->toggleBreak($this->employee, 'lunch');
        $this->atManila('2026-07-02 02:00');
        $row = $this->svc->toggleBreak($this->employee, 'lunch');

        $this->assertSame('2026-07-01', $row->work_date->toDateString());
        $this->assertSame(60, $row->lunch_minutes);
    }

    /** Starting a second shift while one is still open would orphan the first. */
    public function test_clocking_in_again_with_an_open_shift_is_rejected(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);

        $this->atManila('2026-07-02 05:00');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('open shift from Jul 1');
        $this->svc->clockIn($this->employee);
    }

    /**
     * A punch left open past MAX_OPEN_SHIFT_HOURS is a forgotten clock-out, not an active
     * shift — it must not block the next day, and must not be closable a day later.
     */
    public function test_a_stale_punch_does_not_block_the_next_day(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);

        // Next evening — well past 18h.
        $this->atManila('2026-07-02 22:00');
        $fresh = $this->svc->clockIn($this->employee);

        $this->assertSame('2026-07-02', $fresh->work_date->toDateString());
        $this->assertSame(2, Attendance::count());
        // The forgotten one is left open for the violation engine / HR to handle.
        $this->assertNull(Attendance::where('work_date', '2026-07-01')->first()->time_out);
    }

    /** A normal day shift is completely unaffected. */
    public function test_a_same_day_shift_still_works(): void
    {
        $this->atManila('2026-07-06 08:00');
        $this->svc->clockIn($this->employee);

        $this->atManila('2026-07-06 17:00');
        $out = $this->svc->clockOut($this->employee);

        $this->assertSame('2026-07-06', $out->work_date->toDateString());
        $this->assertSame(9.0, (float) $out->hours_worked);
    }

    /** With nothing open, the original error message is preserved. */
    public function test_clocking_out_without_clocking_in_still_errors(): void
    {
        $this->atManila('2026-07-06 17:00');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You have not clocked in today.');
        $this->svc->clockOut($this->employee);
    }

    /** The widget reads the shift in progress, so it stays correct past midnight. */
    public function test_today_for_returns_the_open_overnight_shift(): void
    {
        $this->atManila(self::SHIFT_START);
        $this->svc->clockIn($this->employee);

        $this->atManila('2026-07-02 03:00');

        $this->assertSame('2026-07-01', $this->svc->todayFor($this->employee)?->work_date->toDateString());
    }
}
