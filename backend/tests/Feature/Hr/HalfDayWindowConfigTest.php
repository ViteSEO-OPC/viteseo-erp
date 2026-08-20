<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Where a half day's window COMES FROM, and what happens when there isn't one. */
class HalfDayWindowConfigTest extends HrTestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-24'; // a Monday

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        app(AttendancePolicyService::class)->get();
    }

    /** @param array<string, mixed> $schedule */
    private function makeEmployee(array $schedule): Employee
    {
        WorkSchedule::create(array_merge([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:30', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 90, 'paid_break_minutes' => 0, 'is_default' => true,
        ], $schedule));

        $dept = Department::firstOrCreate(['code' => 'OPS'], ['name' => 'Ops']);

        return $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function approveHalfDay(string $period): void
    {
        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'period' => $period, 'reason' => 'x', 'status' => 'approved',
        ]);
    }

    private function clockInAt(string $time): Attendance
    {
        $this->travelTo(Carbon::parse(self::DATE . " {$time}", config('app.timezone_local')));

        return app(AttendanceService::class)->clockIn($this->employee);
    }

    // --- the per-employee override ---------------------------------------------------------

    public function test_an_employee_can_override_the_afternoon_start(): void
    {
        $this->makeEmployee([
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:30',
        ]);
        // Their own afternoons begin at 13:30, not the template's 13:00.
        $this->employee->update(['custom_pm_start_time' => '13:30']);
        $this->approveHalfDay('AM');

        $this->assertSame('present', $this->clockInAt('13:30')->status);
    }

    public function test_a_blank_override_field_falls_through_to_the_schedule(): void
    {
        $this->makeEmployee([
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:30',
        ]);
        // Only the START is overridden — the END must still come from the template.
        $this->employee->update(['custom_pm_start_time' => '13:30']);

        $eff = app(ScheduleService::class)->effectiveFor(
            $this->employee->fresh(),
            Carbon::parse(self::DATE, config('app.timezone_local')),
        );

        $this->assertSame('13:30', $eff['pm_start_time']);
        $this->assertSame('17:30', $eff['pm_end_time']);
        $this->assertSame('employee_override', $eff['source']);
    }

    public function test_the_override_still_catches_a_genuinely_late_arrival(): void
    {
        $this->makeEmployee([
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:30',
        ]);
        $this->employee->update(['custom_pm_start_time' => '13:30']);
        $this->approveHalfDay('AM');

        // 14:00 against a 13:30 start + 15 grace — half an hour late, measured from THEIR start.
        $row = $this->clockInAt('14:00');

        $this->assertSame('late', $row->status);
        $this->assertSame(
            [self::DATE => 30],
            app(AttendanceService::class)->lateMinutesFor($this->employee, self::DATE, self::DATE),
        );
    }

    public function test_the_override_reaches_payroll_too(): void
    {
        $this->makeEmployee([
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:30',
        ]);
        $this->employee->update(['custom_pm_start_time' => '13:30']);
        $this->approveHalfDay('AM');

        $policy = app(AttendancePolicyService::class)->get();
        $policy->forceFill([
            'late_deduction_mode' => 'per_hour', 'deduction_rounding' => 'exact',
            'late_minutes_from' => 'start_time',
        ])->save();

        $this->clockInAt('14:00');
        $this->travelTo(Carbon::parse(self::DATE . ' 17:30', config('app.timezone_local')));
        app(AttendanceService::class)->clockOut($this->employee);

        $this->travelTo(Carbon::parse('2026-08-31 12:00', config('app.timezone_local')));
        $detail = app(AttendanceService::class)->unpaidDaysDetailFor(
            $this->employee, self::DATE, self::DATE, \App\Modules\Hr\Models\AttendancePolicy::first(),
        );

        // 30 minutes from 13:30 — not 60 from the template's 13:00.
        $this->assertSame(30, $detail->lateMinutes);
    }

    // --- no window configured ----------------------------------------------------------------

    public function test_an_unconfigured_half_is_not_judged_late(): void
    {
        $this->makeEmployee([
            'am_start_time' => null, 'am_end_time' => null,
            'pm_start_time' => null, 'pm_end_time' => null,
        ]);
        $this->approveHalfDay('AM');

        // Against the full shift this reads as five hours late. There is no afternoon window to
        // measure it against, so it is not judged at all.
        $this->assertSame('present', $this->clockInAt('13:00')->status);
        $this->assertSame(
            [],
            app(AttendanceService::class)->lateMinutesFor($this->employee, self::DATE, self::DATE),
        );
    }

    public function test_an_unconfigured_half_charges_no_late_minutes(): void
    {
        $this->makeEmployee([
            'am_start_time' => null, 'am_end_time' => null,
            'pm_start_time' => null, 'pm_end_time' => null,
        ]);
        $this->approveHalfDay('AM');

        $policy = app(AttendancePolicyService::class)->get();
        $policy->forceFill([
            'late_deduction_mode' => 'per_hour', 'deduction_rounding' => 'exact',
            'undertime_deduction_mode' => 'per_hour', 'late_minutes_from' => 'start_time',
        ])->save();

        $this->clockInAt('13:00');
        $this->travelTo(Carbon::parse(self::DATE . ' 17:30', config('app.timezone_local')));
        app(AttendanceService::class)->clockOut($this->employee);

        $this->travelTo(Carbon::parse('2026-08-31 12:00', config('app.timezone_local')));
        $detail = app(AttendanceService::class)->unpaidDaysDetailFor(
            $this->employee, self::DATE, self::DATE, \App\Modules\Hr\Models\AttendancePolicy::first(),
        );

        $this->assertSame(0, $detail->lateMinutes);
        $this->assertSame(0, $detail->undertimeMinutes);
        // Just the half day itself — nothing invented on top of it.
        $this->assertEqualsWithDelta(0.5, $detail->unpaidDays, 0.0001);
    }

    public function test_an_unconfigured_half_charges_no_early_out_either(): void
    {
        $this->makeEmployee([
            'am_start_time' => null, 'am_end_time' => null,
            'pm_start_time' => null, 'pm_end_time' => null,
        ]);
        $this->approveHalfDay('PM');
        EarlyOutRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'early_out_time' => '10:00', 'reason' => 'x', 'status' => 'approved',
        ]);
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'time_in' => Carbon::parse(self::DATE . ' 08:00', config('app.timezone_local'))->utc(),
            'time_out' => Carbon::parse(self::DATE . ' 10:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
        ]);

        $this->travelTo(Carbon::parse('2026-08-31 12:00', config('app.timezone_local')));
        $detail = app(AttendanceService::class)->unpaidDaysDetailFor(
            $this->employee, self::DATE, self::DATE, \App\Modules\Hr\Models\AttendancePolicy::first(),
        );

        $this->assertEqualsWithDelta(0.5, $detail->unpaidDays, 0.0001);
    }

    public function test_an_ordinary_full_day_is_unaffected_by_blank_half_windows(): void
    {
        $this->makeEmployee([
            'am_start_time' => null, 'am_end_time' => null,
            'pm_start_time' => null, 'pm_end_time' => null,
        ]);

        // No half day: the full shift is still the shift, and 09:00 is still late.
        $this->assertSame('late', $this->clockInAt('09:00')->status);
    }

    public function test_an_override_can_supply_a_window_the_schedule_lacks(): void
    {
        $this->makeEmployee([
            'am_start_time' => null, 'am_end_time' => null,
            'pm_start_time' => null, 'pm_end_time' => null,
        ]);
        $this->employee->update([
            'custom_pm_start_time' => '13:30', 'custom_pm_end_time' => '17:30',
        ]);
        $this->approveHalfDay('AM');

        $this->assertSame('present', $this->clockInAt('13:30')->status);
        $this->assertSame('late', $this->clockInAt2('14:00'));
    }

    /** Second clock-in on a fresh day is impossible, so assert the status a 14:00 punch WOULD get. */
    private function clockInAt2(string $time): string
    {
        $row = app(AttendanceService::class)->adjust($this->employee, self::DATE, ['time_in' => $time]);

        return $row->status;
    }

    // --- the schedule form now insists on the windows ----------------------------------------

    /** @param array<string, mixed> $overrides */
    private function schedulePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:30',
            'grace_minutes' => 15, 'unpaid_break_minutes' => 90, 'paid_break_minutes' => 0,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:30', 'pm_end_time' => '17:30',
            'is_default' => true,
        ], $overrides);
    }

    public function test_a_schedule_cannot_be_saved_without_its_half_day_windows(): void
    {
        // Blank is not a neutral default: it is the state in which an approved half day cannot be
        // judged at all, and it cannot be derived from the shift. So it is asked for here.
        $this->hrPost('/api/v1/hr/work-schedules', $this->schedulePayload([
            'am_start_time' => null, 'am_end_time' => null,
            'pm_start_time' => null, 'pm_end_time' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'am_start_time', 'am_end_time', 'pm_start_time', 'pm_end_time',
        ]);
    }

    public function test_a_partial_half_day_window_is_refused_on_the_schedule(): void
    {
        // The TEMPLATE has to be complete — unlike a per-employee override, it has nothing to
        // inherit from.
        $this->hrPost('/api/v1/hr/work-schedules', $this->schedulePayload([
            'pm_end_time' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors(['pm_end_time']);
    }

    public function test_a_complete_schedule_still_saves(): void
    {
        $this->hrPost('/api/v1/hr/work-schedules', $this->schedulePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.pm_start_time', '13:30');
    }

    public function test_an_employee_override_may_be_partial_over_http(): void
    {
        $this->makeEmployee([
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:30',
        ]);

        $this->actingAsHr()
            ->patchJson("/api/v1/hr/employees/{$this->employee->id}/schedule", [
                'custom_pm_start_time' => '13:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.custom_pm_start_time', '13:30')
            ->assertJsonPath('data.custom_pm_end_time', null)
            // …and the effective schedule fills the gap from the template.
            ->assertJsonPath('data.effective.pm_start_time', '13:30')
            ->assertJsonPath('data.effective.pm_end_time', '17:30');
    }
}
