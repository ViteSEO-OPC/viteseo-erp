<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\OvertimeRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Slice 6 (2026-07-28 audit) — overtime is paid against the clock, and HR can undo it.
 *
 * `approvedOvertimeMinutes()` read the request table alone, so an approved request paid in
 * full even on a day the employee never showed up — while that same day was ALSO docked as
 * an absence. And once approved a request was frozen: `approve()`/`reject()` both guard on
 * `pending`, so there was no way to stop overtime that never happened.
 */
class OvertimeAttendanceTest extends HrTestCase
{
    use RefreshDatabase;

    /** A Monday, firmly in the past. */
    private const WORKDAY = '2026-03-02';

    /** The Sunday before it — a rest day on the Mon–Fri schedule. */
    private const RESTDAY = '2026-03-01';

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

    private function clocked(string $date, string $in, string $out, int $lunch = 60): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$in}", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} {$out}", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => $lunch, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function approvedOt(string $date, float $hours): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'hours' => $hours, 'reason' => 'Deadline', 'status' => 'approved',
        ]);
    }

    /** @return array<string, int> date => payable overtime minutes */
    private function capacity(): array
    {
        return app(AttendanceService::class)
            ->overtimeCapacityFor($this->employee, self::RESTDAY, self::WORKDAY);
    }

    /** THE BUG: approved overtime on a day with no attendance at all. */
    public function test_no_attendance_means_no_overtime_capacity(): void
    {
        $this->approvedOt(self::WORKDAY, 3);

        $this->assertSame([], $this->capacity());
    }

    /** A missing clock-out can never become overtime (rule 5). */
    public function test_a_missing_clock_out_supports_no_overtime(): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::WORKDAY,
            'time_in' => Carbon::parse(self::WORKDAY . ' 08:00', $tz)->utc(),
            'status' => 'present', 'source' => Attendance::SOURCE_MANUAL,
        ]);

        $this->assertSame([], $this->capacity());
    }

    /** On a workday, capacity is the time clocked past the scheduled end. */
    public function test_capacity_on_a_workday_is_time_past_the_shift_end(): void
    {
        $this->clocked(self::WORKDAY, '08:00', '19:00'); // 2h past 17:00

        $this->assertSame([self::WORKDAY => 120], $this->capacity());
    }

    /** Leaving on time supports no overtime, however long the clock reading. */
    public function test_leaving_at_the_shift_end_supports_no_overtime(): void
    {
        $this->clocked(self::WORKDAY, '06:00', '17:00');

        $this->assertSame([], $this->capacity());
    }

    /** A rest day has no shift end — the first 8 worked hours are the premium, not overtime. */
    public function test_capacity_on_a_rest_day_is_the_excess_past_eight_hours(): void
    {
        // 08:00–19:00 with a 60-min meal = 10h worked → 2h beyond the premium block.
        $this->clocked(self::RESTDAY, '08:00', '19:00');

        $this->assertSame([self::RESTDAY => 120], $this->capacity());
    }

    public function test_a_short_rest_day_supports_no_overtime(): void
    {
        $this->clocked(self::RESTDAY, '08:00', '13:00'); // 4h worked, all premium

        $this->assertSame([], $this->capacity());
    }

    public function test_management_never_accrues_overtime_capacity(): void
    {
        $this->clocked(self::WORKDAY, '08:00', '20:00');
        $this->employee->update(['is_management' => true]);

        $this->assertSame([], $this->capacity());
    }

    // --- cancel -------------------------------------------------------------------

    private function hr(): User
    {
        if (Role::query()->where('name', 'hr_officer')->doesntExist()) {
            $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        }
        $user = User::factory()->create();
        $user->assignRole('hr_officer');

        return $user;
    }

    /** THE OTHER HALF: an approved request used to be frozen forever. */
    public function test_hr_can_cancel_an_approved_overtime_request(): void
    {
        $req = $this->approvedOt(self::WORKDAY, 3);

        $cancelled = app(OvertimeRequestService::class)
            ->cancel($req, $this->hr(), 'Work was rescheduled');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Work was rescheduled', $cancelled->cancel_reason);
    }

    /** Payroll stops seeing it from the next recompute. */
    public function test_a_cancelled_request_is_ignored_by_payroll(): void
    {
        $this->clocked(self::WORKDAY, '08:00', '20:00');
        $req = $this->approvedOt(self::WORKDAY, 3);

        app(OvertimeRequestService::class)->cancel($req, $this->hr(), 'Not needed');

        $this->assertSame(0, OvertimeRequest::where('status', 'approved')->count());
    }

    /** An employee cannot cancel their own approved request — HR only. */
    public function test_an_employee_cannot_cancel(): void
    {
        $req = $this->approvedOt(self::WORKDAY, 3);
        $owner = $this->employee->user;

        $this->expectException(AuthorizationException::class);
        app(OvertimeRequestService::class)->cancel($req, $owner, 'Changed my mind');
    }

    public function test_a_rejected_request_cannot_be_cancelled(): void
    {
        $req = $this->approvedOt(self::WORKDAY, 3);
        $req->update(['status' => 'rejected']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot cancel a request that is rejected.');
        app(OvertimeRequestService::class)->cancel($req, $this->hr(), 'nope');
    }

    public function test_the_cancel_endpoint_requires_a_reason(): void
    {
        $req = $this->approvedOt(self::WORKDAY, 3);

        $this->hrPost("/api/v1/hr/overtime-requests/{$req->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->hrPost("/api/v1/hr/overtime-requests/{$req->id}/cancel", ['reason' => 'Cancelled by ops'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
