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

/**
 * Guards the Slice 2 wiring where DeductionService feeds unpaidDaysFor(). The headline
 * assertion is the NO-OP GATE: with the seeded default policy, the detail total equals
 * the legacy float and the breakdown is all-zero — enabling per_hour is the ONLY thing
 * that moves a peso.
 */
class AttendanceDeductionWiringTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private string $start;

    private string $end;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');
        // Two weekdays firmly in the past so they classify as worked/late, not "future".
        $mon = Carbon::now($tz)->subWeek()->startOfWeek(); // Monday
        $tue = $mon->copy()->addDay();
        $this->start = $mon->toDateString();
        $this->end = $tue->toDateString();

        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '18:00', 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get(); // seed the singleton at its defaults

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $user = User::factory()->create();
        $this->employee = Employee::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);

        // Monday: 60 min late (09:00 vs 08:00), full clock-out. Tuesday: on time.
        $this->clockRow($mon, '09:00', '18:00', 'late');
        $this->clockRow($tue, '08:00', '18:00', 'present');
    }

    private function clockRow(Carbon $day, string $in, string $out, string $status): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $day->toDateString(),
            'time_in' => Carbon::parse("{$day->toDateString()} {$in}", $tz)->utc(),
            'time_out' => Carbon::parse("{$day->toDateString()} {$out}", $tz)->utc(),
            'status' => $status,
            'lunch_minutes' => 60, 'break_minutes' => 20,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    public function test_default_policy_is_a_no_op(): void
    {
        $svc = app(AttendanceService::class);

        $detail = $svc->unpaidDaysDetailFor($this->employee, $this->start, $this->end);
        $legacy = $svc->unpaidDaysFor($this->employee, $this->start, $this->end);

        // Both days worked → zero unpaid; the breakdown adds nothing under the default policy.
        $this->assertSame(0.0, $detail->unpaidDays);
        $this->assertSame($detail->unpaidDays, $legacy);
        $this->assertSame(0, $detail->lateMinutes);
        $this->assertSame(0, $detail->undertimeMinutes);
        $this->assertSame(0.0, $detail->lateDays);
    }

    public function test_enabling_per_hour_prices_the_late_arrival(): void
    {
        AttendancePolicy::query()->update(['late_deduction_mode' => 'per_hour']);

        $detail = app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee, $this->start, $this->end);

        // 60 min late, priced ÷ 8 (480-min day) → 60/480 = 0.125 of a day.
        $this->assertSame(60, $detail->lateMinutes);
        $this->assertEqualsWithDelta(0.125, $detail->unpaidDays, 1e-9);
        $this->assertEqualsWithDelta(0.125, $detail->lateDays, 1e-9);
    }

    public function test_management_is_never_deducted(): void
    {
        AttendancePolicy::query()->update(['late_deduction_mode' => 'per_hour']);
        $this->employee->update(['is_management' => true]);

        $detail = app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee, $this->start, $this->end);

        $this->assertSame(0.0, $detail->unpaidDays);
        $this->assertSame(0, $detail->lateMinutes);
    }
}
