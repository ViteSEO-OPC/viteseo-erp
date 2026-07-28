<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // 22:00 → 06:00 is entirely inside the 22:00–06:00 window.
        $this->assertSame(['2026-07-01' => 480], $night);
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
