<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\DailyOverride;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** The per-day "minimum hours = full day" override (hr_daily_overrides.min_full_day_minutes). */
class MinimumHoursFullDayTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private string $date;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');
        // A weekday firmly in the past, so it classifies as worked rather than "future".
        $this->day = Carbon::now($tz)->subWeek()->startOfWeek(); // Monday
        $this->date = $this->day->toDateString();

        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '18:00', 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);

        // The charging modes this feature interacts with. Off by default in production; the
        // whole point of the bar only becomes visible once undertime actually costs money.
        AttendancePolicy::query()->update(['undertime_deduction_mode' => 'per_hour']);
    }

    private function clock(string $in, string $out, string $status = 'present'): void
    {
        $tz = config('app.timezone_local');
        Attendance::updateOrCreate(
            ['employee_id' => $this->employee->id, 'work_date' => $this->date],
            [
                'time_in' => Carbon::parse("{$this->date} {$in}", $tz)->utc(),
                'time_out' => Carbon::parse("{$this->date} {$out}", $tz)->utc(),
                'status' => $status,
                'lunch_minutes' => 60, 'break_minutes' => 20,
                'source' => Attendance::SOURCE_MANUAL,
            ],
        );
    }

    private function declareBar(?int $minutes): void
    {
        if ($minutes === null) {
            DailyOverride::where('date', $this->date)->delete();

            return;
        }
        DailyOverride::updateOrCreate(['date' => $this->date], ['min_full_day_minutes' => $minutes]);
    }

    /** A fresh instance each time — the service memoizes rows and rules per run. */
    private function price(): \App\Modules\Hr\Services\UnpaidDaysResult
    {
        return app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee->fresh(), $this->date, $this->date);
    }

    // --- the baseline this feature exists to change --------------------------------------

    public function test_without_a_bar_leaving_at_three_is_three_hours_of_undertime(): void
    {
        $this->clock('08:00', '15:00');

        $detail = $this->price();

        $this->assertSame(180, $detail->undertimeMinutes);
        $this->assertEqualsWithDelta(0.375, $detail->unpaidDays, 1e-9); // 180 ÷ 480
    }

    public function test_a_declared_bar_makes_the_same_day_whole(): void
    {
        $this->clock('08:00', '15:00');
        $this->declareBar(360);

        $detail = $this->price();

        $this->assertSame(0, $detail->undertimeMinutes);
        $this->assertSame(0.0, $detail->unpaidDays);
        $this->assertContains('full_day_credited', array_column($detail->lines, 'kind'));
    }

    // --- lateness is deliberately NOT forgiven --------------------------------------------

    public function test_a_late_arrival_that_clears_the_bar_still_pays_the_late_deduction(): void
    {
        AttendancePolicy::query()->update(['late_deduction_mode' => 'per_hour']);
        $this->clock('09:00', '16:00', 'late'); // an hour late, still six hours worked
        $this->declareBar(360);

        $detail = $this->price();

        $this->assertSame(0, $detail->undertimeMinutes, 'undertime is waived');
        $this->assertGreaterThan(0, $detail->lateMinutes, 'lateness is not');
        // The day costs the late deduction and nothing else.
        $this->assertEqualsWithDelta($detail->lateDays, $detail->unpaidDays, 1e-9);
    }

    // --- it can only ever forgive ---------------------------------------------------------

    public function test_falling_short_of_the_bar_costs_exactly_what_it_costs_today(): void
    {
        $this->clock('08:00', '12:00'); // four hours — nowhere near a six-hour bar

        $this->declareBar(null);
        $without = $this->price();

        $this->declareBar(360);
        $with = $this->price();

        $this->assertEqualsWithDelta($without->unpaidDays, $with->unpaidDays, 1e-9);
        $this->assertSame($without->undertimeMinutes, $with->undertimeMinutes);
        $this->assertGreaterThan(0.0, $with->unpaidDays, 'the day really was short');
    }

    public function test_an_unreachable_bar_never_penalises_a_short_scheduled_day(): void
    {
        // A ten-hour bar cannot be met on a nine-hour paid day. Nobody may be worse off for it.
        $this->clock('08:00', '18:00');

        $this->declareBar(null);
        $without = $this->price();

        $this->declareBar(600);
        $with = $this->price();

        $this->assertSame(0.0, $without->unpaidDays);
        $this->assertEqualsWithDelta($without->unpaidDays, $with->unpaidDays, 1e-9);
    }

    // --- approved half-days ---------------------------------------------------------------

    public function test_a_half_day_worked_as_approved_is_not_double_charged_by_the_bar(): void
    {
        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => $this->date,
            'period' => 'PM', 'status' => 'approved', 'reason' => 'errand',
        ]);
        $this->clock('08:00', '12:00'); // exactly the half they were owed

        $this->declareBar(null);
        $without = $this->price();

        $this->declareBar(360);
        $with = $this->price();

        // Half a day, once — the bar adds nothing on top of the half they requested.
        $this->assertEqualsWithDelta(0.5, $without->unpaidDays, 1e-9);
        $this->assertEqualsWithDelta(0.5, $with->unpaidDays, 1e-9);
    }

    public function test_working_on_through_a_half_day_to_the_bar_cancels_the_half(): void
    {
        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => $this->date,
            'period' => 'PM', 'status' => 'approved', 'reason' => 'errand',
        ]);
        // They had the afternoon off and worked it anyway, reaching six hours at 15:00.
        $this->clock('08:00', '15:00');
        $this->declareBar(360);

        $detail = $this->price();

        $this->assertSame(0.0, $detail->unpaidDays, 'the half stops costing them');
        $this->assertContains('full_day_credited', array_column($detail->lines, 'kind'));
    }

    // --- approved early-outs ---------------------------------------------------------------

    public function test_clearing_the_bar_cancels_an_approved_early_out(): void
    {
        EarlyOutRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => $this->date,
            'early_out_time' => '15:00', 'status' => 'approved', 'reason' => 'clinic',
        ]);
        $this->clock('08:00', '15:00');

        $this->declareBar(null);
        $without = $this->price();

        $this->declareBar(360);
        $with = $this->price();

        $this->assertGreaterThan(0.0, $without->unpaidDays, 'an early-out normally costs');
        $this->assertSame(0.0, $with->unpaidDays);
    }

    // --- the overtime gate ------------------------------------------------------------------

    public function test_reaching_the_bar_past_the_scheduled_end_needs_approved_overtime(): void
    {
        // The morning is approved off, so the owed window is 13:00–18:00 — five hours, an hour
        // short of the bar. The only way to reach it is to work past 18:00, and that is exactly
        // where authorisation starts to matter: unapproved time is not credited, so the half
        // still costs them. This is the case that decides whether the escape hatch is real.
        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => $this->date,
            'period' => 'AM', 'status' => 'approved', 'reason' => 'errand',
        ]);
        $this->clock('13:00', '19:00');
        $this->declareBar(360);

        $this->assertEqualsWithDelta(
            0.5, $this->price()->unpaidDays, 1e-9,
            'unapproved time past the scheduled end does not count toward the bar',
        );

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => $this->date,
            'time_from' => '18:00', 'time_to' => '19:00', 'hours' => 1,
            'status' => 'approved', 'reason' => 'deadline',
        ]);

        $this->assertSame(0.0, $this->price()->unpaidDays, 'approved overtime does');
    }

    // --- the badge, which is computed independently of the money ----------------------------

    public function test_a_credited_day_is_not_flagged_as_undertime(): void
    {
        $this->clock('08:00', '15:00');

        $this->declareBar(null);
        $this->assertSame(
            [$this->date => 180],
            app(AttendanceService::class)->undertimeMinutesFor($this->employee->fresh(), $this->date, $this->date),
        );

        $this->declareBar(360);
        $this->assertSame(
            [],
            app(AttendanceService::class)->undertimeMinutesFor($this->employee->fresh(), $this->date, $this->date),
            'the badge and the payslip must agree the bar was cleared',
        );
    }

    // --- what the employee is told -----------------------------------------------------------

    public function test_the_employee_is_told_the_rule_and_when_they_reach_it(): void
    {
        $today = Carbon::now(config('app.timezone_local'));
        // dayRulesFor() answers about TODAY, so the fixture has to be today.
        if (! in_array($today->isoWeekday(), [1, 2, 3, 4, 5], true)) {
            $this->markTestSkipped('dayRulesFor() reports on today, which is not a workday here.');
        }

        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $today->toDateString(),
            'time_in' => Carbon::parse("{$today->toDateString()} 08:00", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 0, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
        DailyOverride::updateOrCreate(
            ['date' => $today->toDateString()],
            ['min_full_day_minutes' => 360],
        );

        $rules = app(AttendanceService::class)->dayRulesFor($this->employee->fresh());

        $this->assertSame(360, $rules['min_full_day_minutes']);
        $this->assertSame('15:00', $rules['full_day_at']);
        $this->assertFalse($rules['full_day_met'], 'still clocked in');
    }

    public function test_no_declared_bar_tells_the_employee_nothing(): void
    {
        $rules = app(AttendanceService::class)->dayRulesFor($this->employee->fresh());

        $this->assertNull($rules['min_full_day_minutes']);
        $this->assertNull($rules['full_day_at']);
        $this->assertFalse($rules['full_day_met']);
    }
}
