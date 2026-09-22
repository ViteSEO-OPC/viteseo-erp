<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\DailyOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * BENDING A PAST DAY'S GRACE, and the stored lateness following it.
 *
 * The money was always derived from the punches, so a past-dated grace override already moved
 * the late deduction on the next run. The stored `status` was a snapshot taken at clock-in and
 * did not move with it — the figure changed and the row still read "Late", which also kept the
 * per-N-lates ladder counting a day nobody was charged for.
 */
class RetroactiveDayRulesTest extends HrTestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-24'; // a Monday

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** Arrive at $time on the fixed date, then return to the present. */
    private function clockInAt(string $time): Attendance
    {
        $this->travelTo(Carbon::parse(self::DATE . " {$time}", config('app.timezone_local')));
        $row = app(AttendanceService::class)->clockIn($this->employee);
        $this->travelBack();

        return $row;
    }

    private function setGrace(?int $minutes, bool $ack = false): void
    {
        app(DailyOverrideService::class)->upsert(self::DATE, $minutes, null, $ack);
    }

    public function test_a_wider_grace_clears_a_past_late(): void
    {
        $row = $this->clockInAt('08:20:00');
        $this->assertSame('late', $row->status);

        $this->setGrace(30);

        $this->assertSame('present', $row->fresh()->status);
        // And the money agrees — the derived side was already reading the override.
        $this->assertSame(
            [],
            app(AttendanceService::class)->lateMinutesFor($this->employee, self::DATE, self::DATE),
        );
    }

    public function test_a_narrower_grace_makes_a_past_arrival_late(): void
    {
        $row = $this->clockInAt('08:10:00');
        $this->assertSame('present', $row->status);

        $this->setGrace(5);

        $this->assertSame('late', $row->fresh()->status);
        $this->assertSame(
            10,
            app(AttendanceService::class)->lateMinutesFor($this->employee, self::DATE, self::DATE)[self::DATE] ?? 0,
        );
    }

    public function test_clearing_the_override_restores_the_schedule_grace(): void
    {
        $row = $this->clockInAt('08:20:00');
        $this->setGrace(30);
        $this->assertSame('present', $row->fresh()->status);

        $this->setGrace(null);

        // Back to the schedule's own 15 minutes, under which 08:20 was always late.
        $this->assertSame('late', $row->fresh()->status);
    }

    public function test_an_hr_asserted_status_is_never_re_judged(): void
    {
        $row = $this->clockInAt('08:20:00');
        $row->update(['status' => 'present', 'status_override' => true]);

        $this->setGrace(5); // would make 08:20 late twice over

        $this->assertSame('present', $row->fresh()->status);
    }

    public function test_a_non_arrival_status_is_left_alone(): void
    {
        $row = $this->clockInAt('08:20:00');
        $row->update(['status' => 'on_leave']);

        $this->setGrace(5);

        $this->assertSame('on_leave', $row->fresh()->status);
    }

    public function test_a_date_inside_a_completed_run_asks_first(): void
    {
        $this->clockInAt('08:20:00');

        PayrollRun::create([
            'cycle_start' => '2026-08-16', 'cycle_end' => '2026-08-31',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'run_at' => now(),
        ]);

        try {
            $this->setGrace(30);
            $this->fail('Expected the completed run to be named before the override was written.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Aug 16–Aug 31, 2026',
                $e->validator->errors()->first('date'),
            );
        }

        // Nothing was written, and nothing was re-judged.
        $this->assertNull(app(DailyOverrideService::class)->get(self::DATE));
        $this->assertSame('late', Attendance::whereDate('work_date', self::DATE)->first()->status);

        // Acknowledged, it goes through.
        $this->setGrace(30, ack: true);
        $this->assertSame('present', Attendance::whereDate('work_date', self::DATE)->first()->status);
    }

    /**
     * The minimum-hours bar has no stored mirror the way lateness does — `creditedFullDay()`
     * re-derives it from the punches on every ask — so moving it for a past date must not
     * disturb the arrival statuses on its way through.
     */
    public function test_the_full_day_bar_does_not_touch_arrival_statuses(): void
    {
        $row = $this->clockInAt('08:20:00');
        $this->assertSame('late', $row->status);

        app(DailyOverrideService::class)->upsert(self::DATE, null, 360);

        // Still late — the bar says nothing about when anybody arrived.
        $this->assertSame('late', $row->fresh()->status);
        $this->assertSame(360, app(DailyOverrideService::class)->get(self::DATE)?->min_full_day_minutes);
    }

    /** And the two knobs are independent: setting one must not clear the other. */
    public function test_the_two_rules_are_set_independently(): void
    {
        $row = $this->clockInAt('08:20:00');

        app(DailyOverrideService::class)->upsert(self::DATE, 30, 360);
        $this->assertSame('present', $row->fresh()->status);

        // Drop only the bar, keep the grace.
        app(DailyOverrideService::class)->upsert(self::DATE, 30, null);

        $override = app(DailyOverrideService::class)->get(self::DATE);
        $this->assertSame(30, $override?->grace_minutes);
        $this->assertNull($override?->min_full_day_minutes);
        $this->assertSame('present', $row->fresh()->status);
    }

    public function test_saving_the_same_values_changes_nothing(): void
    {
        $this->clockInAt('08:20:00');
        $this->setGrace(30);

        PayrollRun::create([
            'cycle_start' => '2026-08-16', 'cycle_end' => '2026-08-31',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'run_at' => now(),
        ]);

        // Re-saving what the day already says is not a change, so it must not demand an
        // acknowledgement for a repricing that is not happening.
        $this->setGrace(30);

        $this->assertSame(30, app(DailyOverrideService::class)->get(self::DATE)?->grace_minutes);
    }
}
