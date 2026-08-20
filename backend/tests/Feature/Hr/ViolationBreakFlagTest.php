<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** (2026-07-28 audit) — the break rule was configurable and did nothing. */
class ViolationBreakFlagTest extends HrTestCase
{
    use RefreshDatabase;

    /** Tuesday 16 June 2026 — a fixed weekday, mid-month, inside recompute()'s scan window. */
    private Carbon $day;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');

        // THE CLOCK IS FROZEN ON PURPOSE (payroll audit 2026-08-04).
        $this->travelTo(Carbon::parse('2026-06-17 12:00', $tz));
        $this->day = Carbon::parse('2026-06-16', $tz)->startOfDay();

        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            // Entitlement = 60 + 20 = 80 minutes.
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get(); // grace defaults to 15

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);
    }

    private function worked(int $lunch, int $break): void
    {
        $tz = config('app.timezone_local');
        $date = $this->day->toDateString();
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} 08:00", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} 17:00", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => $lunch, 'break_minutes' => $break,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function longBreakFlags(): \Illuminate\Support\Collection
    {
        app(ViolationService::class)->recompute();

        return Violation::where('employee_id', $this->employee->id)
            ->where('type', 'long_break')
            ->get();
    }

    /** Lunch allowance 60 + 15 grace = 75. Taking 90 is over. */
    public function test_an_overlong_lunch_is_flagged(): void
    {
        $this->worked(lunch: 90, break: 20);

        $flags = $this->longBreakFlags();

        $this->assertCount(1, $flags);
        $this->assertStringContainsString('Lunch ran 30 min over the 60 min allowance', $flags->first()->details);
    }

    /** Break allowance 20 + 15 grace = 35. Judged on its own, not pooled with lunch. */
    public function test_an_overlong_break_is_flagged(): void
    {
        $this->worked(lunch: 60, break: 50);

        $flags = $this->longBreakFlags();

        $this->assertCount(1, $flags);
        $this->assertStringContainsString('Break ran 30 min over the 20 min allowance', $flags->first()->details);
    }

    /** Both over → one flag for the day, naming each. */
    public function test_both_over_produce_one_flag_naming_each(): void
    {
        $this->worked(lunch: 100, break: 60);

        $details = $this->longBreakFlags()->first()->details;

        $this->assertStringContainsString('Lunch ran 40 min', $details);
        $this->assertStringContainsString('Break ran 40 min', $details);
    }

    /**
     * THE REASON FOR PER-BUCKET. A 20-min lunch plus a 60-min break totals the same 80 as a
     * normal day, so pooling never flagged it — while the break ran 3× its allowance.
     */
    public function test_a_short_lunch_cannot_bankroll_a_long_break(): void
    {
        $this->worked(lunch: 20, break: 60); // 80 total — identical to the entitlement

        $flags = $this->longBreakFlags();

        $this->assertCount(1, $flags);
        $this->assertStringContainsString('Break ran 40 min over', $flags->first()->details);
    }

    /** Within tolerance on both → no flag. This is why the graces exist. */
    public function test_slightly_over_is_within_the_grace(): void
    {
        $this->worked(lunch: 70, break: 30); // 10 over each, both under their 15 grace

        $this->assertCount(0, $this->longBreakFlags());
    }

    public function test_a_normal_break_is_not_flagged(): void
    {
        $this->worked(lunch: 60, break: 20); // exactly the allowances

        $this->assertCount(0, $this->longBreakFlags());
    }

    /** A grace of 0 means no tolerance: exactly the allowance passes, one minute over doesn't. */
    public function test_zero_grace_means_no_tolerance(): void
    {
        AttendancePolicy::query()->update(['lunch_grace_minutes' => 0, 'break_grace_minutes' => 0]);
        $this->worked(lunch: 61, break: 20); // one minute over lunch

        $flags = $this->longBreakFlags();

        $this->assertCount(1, $flags);
        $this->assertStringContainsString('Lunch ran 1 min over', $flags->first()->details);
    }

    public function test_zero_grace_still_allows_exactly_the_allowance(): void
    {
        AttendancePolicy::query()->update(['lunch_grace_minutes' => 0, 'break_grace_minutes' => 0]);
        $this->worked(lunch: 60, break: 20);

        $this->assertCount(0, $this->longBreakFlags());
    }

    /** The two tolerances are independent. */
    public function test_the_graces_are_set_separately(): void
    {
        // Generous on lunch, strict on breaks.
        AttendancePolicy::query()->update(['lunch_grace_minutes' => 60, 'break_grace_minutes' => 0]);
        $this->worked(lunch: 110, break: 25); // lunch within 120, break 5 over

        $flags = $this->longBreakFlags();

        $this->assertCount(1, $flags);
        $this->assertStringNotContainsString('Lunch', $flags->first()->details);
        $this->assertStringContainsString('Break ran 5 min over', $flags->first()->details);
    }

    /**
     * THE POINT OF THE CHANGE: a shorter-lunch schedule gets a proportionally lower threshold.
     * Under the old flat 120-minute company cap this day was never flagged.
     */
    public function test_the_threshold_follows_the_employees_own_schedule(): void
    {
        $this->employee->update(['custom_unpaid_break_minutes' => 30, 'custom_paid_break_minutes' => 0]);
        $this->worked(lunch: 100, break: 0); // 100 min vs a 30 min entitlement

        $flags = $this->longBreakFlags();

        $this->assertCount(1, $flags);
        $this->assertStringContainsString('70 min over the 30 min allowance', $flags->first()->details);
    }

    /** It must fire with every deduction mode off — the old one didn't. */
    public function test_it_is_flagged_with_all_deduction_modes_off(): void
    {
        AttendancePolicy::query()->update([
            'late_deduction_mode' => 'none',
            'undertime_deduction_mode' => 'none',
        ]);
        $this->worked(lunch: 120, break: 30);

        $this->assertCount(1, $this->longBreakFlags());
    }

    /** HR can widen the tolerance. */
    public function test_the_grace_is_configurable(): void
    {
        AttendancePolicy::query()->update(['lunch_grace_minutes' => 60, 'break_grace_minutes' => 60]);
        $this->worked(lunch: 90, break: 40); // 30 and 20 over, both under a 60 grace

        $this->assertCount(0, $this->longBreakFlags());
    }

    /** Flag only — a long break must never move pay. */
    public function test_a_long_break_costs_no_pay(): void
    {
        $this->worked(lunch: 150, break: 60);

        $unpaid = app(\App\Modules\Hr\Services\AttendanceService::class)
            ->unpaidDaysFor($this->employee, $this->day->toDateString(), $this->day->toDateString());

        $this->assertSame(0.0, $unpaid);
    }
}
