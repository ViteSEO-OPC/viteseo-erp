<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** (2026-07-28 audit) — a GUARD, not a fix. Nothing changed in the engine. */
class OvertimeNoNettingTest extends HrTestCase
{
    use RefreshDatabase;

    /** A Monday, firmly in the past. Schedule is 08:00–17:00 with a 60-min unpaid meal. */
    private const WORKDAY = '2026-03-02';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);
    }

    private function clocked(string $in, string $out, int $lunch = 60, string $status = 'present'): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::WORKDAY,
            'time_in' => Carbon::parse(self::WORKDAY . " {$in}", $tz)->utc(),
            'time_out' => Carbon::parse(self::WORKDAY . " {$out}", $tz)->utc(),
            'status' => $status, 'lunch_minutes' => $lunch, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    /** @return array<string, array{minutes:int, night:int}> */
    private function autoOvertime(): array
    {
        return app(AttendanceService::class)
            ->autoOvertimeMinutesFor($this->employee, self::WORKDAY, self::WORKDAY);
    }

    /**
     * THE CASE. In 09:00 (1h late), out 18:00 (1h past end). Overtime is the full 60
     * minutes past `end_time` — NOT zero. Netting it against the late hour is Art. 88
     * non-compliant, so the ledgers stay separate.
     */
    public function test_a_late_arrival_does_not_reduce_overtime(): void
    {
        $this->clocked('09:00', '18:00', status: 'late');

        $this->assertSame(60, $this->autoOvertime()[self::WORKDAY]['minutes'] ?? 0);
    }

    /** The other ledger still charges that same late hour once a deduction mode is on. */
    public function test_the_late_hour_is_still_deducted_separately(): void
    {
        $this->clocked('09:00', '18:00', status: 'late');
        AttendancePolicy::query()->update(['late_deduction_mode' => 'per_hour']);

        $detail = app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee, self::WORKDAY, self::WORKDAY);

        $this->assertSame(60, $detail->lateMinutes);
        $this->assertEqualsWithDelta(0.125, $detail->lateDays, 1e-9); // 60 ÷ (8 × 60)
    }

    /**
     * RULE 2e. Working straight through the unpaid meal is a 10-hour clock reading with ZERO
     * overtime — they left at their scheduled end. `hoursWorked − expectedHours` would have
     * invented 60 minutes here.
     */
    public function test_a_skipped_meal_creates_no_overtime(): void
    {
        $this->clocked('08:00', '17:00', lunch: 0);

        $this->assertSame([], $this->autoOvertime());
    }

    /** Leaving before the shift ends is never negative overtime. */
    public function test_leaving_early_produces_no_overtime(): void
    {
        $this->clocked('08:00', '15:00');

        $this->assertSame([], $this->autoOvertime());
    }

    /** The plain case still works: on time in, an hour past the end out. */
    public function test_staying_past_the_shift_end_is_overtime(): void
    {
        $this->clocked('08:00', '18:00');

        $this->assertSame(60, $this->autoOvertime()[self::WORKDAY]['minutes'] ?? 0);
    }

    /** The threshold still gates trivial overruns. */
    public function test_a_trivial_overrun_below_the_threshold_is_ignored(): void
    {
        $this->clocked('08:00', '17:10');

        $this->assertSame([], app(AttendanceService::class)
            ->autoOvertimeMinutesFor($this->employee, self::WORKDAY, self::WORKDAY, 30));
    }
}
