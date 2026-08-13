<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\RestDayRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\RestDayRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Working a rest day (RD-1/RD-2).
 *
 * A Mon–Fri employee who comes in on a Saturday cannot simply clock in: the day earns a
 * rest-day premium, so somebody has to agree to spend it. Pressing Time in files a request
 * stamped with that moment, and HR's approval writes the attendance row AT that moment —
 * the promise the whole feature rests on, and the first thing these tests pin down.
 */
class RestDayWorkRequestTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** A Saturday. The schedule below is Mon–Fri, so this is a rest day. */
    private const REST_DAY = '2026-07-04';

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::create([
            'name' => 'Day', 'workdays' => [1, 2, 3, 4, 5],
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

    private function service(): RestDayRequestService
    {
        return app(RestDayRequestService::class);
    }

    private function manilaTimeOf(?Carbon $utc): ?string
    {
        return $utc?->copy()->timezone(config('app.timezone_local'))->format('H:i');
    }

    // --- the promise ---------------------------------------------------------------------

    public function test_approval_stamps_the_time_in_at_the_moment_the_employee_asked(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $request = $this->service()->create($this->employee, self::REST_DAY, 'Client deadline');

        // Four hours of HR deliberation must cost the employee nothing.
        $this->atManila(self::REST_DAY . ' 12:00');
        $this->service()->approve($request, $this->hrUser());

        $att = Attendance::where('employee_id', $this->employee->id)
            ->where('work_date', self::REST_DAY)
            ->firstOrFail();

        $this->assertSame('08:00', $this->manilaTimeOf($att->time_in));
        $this->assertSame('present', $att->status);
    }

    public function test_an_approved_rest_day_earns_the_rest_day_premium(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $request = $this->service()->create($this->employee, self::REST_DAY);
        $this->service()->approve($request, $this->hrUser());

        // The employee closes the shift through the ordinary time clock.
        $this->atManila(self::REST_DAY . ' 17:00');
        app(AttendanceService::class)->clockOut($this->employee);

        $worked = app(AttendanceService::class)
            ->restDayWorkedMinutesFor($this->employee, '2026-07-01', '2026-07-31');

        // 9 hours on the clock. The meal comes off only when one was actually taken —
        // restDayWorkedMinutesFor subtracts the RECORDED lunch, not the schedule's allowance.
        $this->assertSame([self::REST_DAY => 540], $worked);
    }

    // --- the gate ------------------------------------------------------------------------

    public function test_clocking_in_on_a_rest_day_writes_no_attendance(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');

        try {
            app(AttendanceService::class)->clockIn($this->employee);
            $this->fail('Clocking in on a rest day should be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Attendance::where('employee_id', $this->employee->id)->count());
    }

    public function test_a_workday_cannot_be_filed_as_a_rest_day_request(): void
    {
        $this->atManila('2026-07-03 08:00'); // a Friday

        $this->expectException(ValidationException::class);
        $this->service()->create($this->employee, '2026-07-03');
    }

    public function test_a_second_request_for_the_same_day_is_refused(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $this->service()->create($this->employee, self::REST_DAY);

        $this->expectException(ValidationException::class);
        $this->service()->create($this->employee, self::REST_DAY);
    }

    public function test_the_panel_is_told_it_is_a_rest_day_and_how_the_request_stands(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $this->service()->create($this->employee, self::REST_DAY);

        $window = app(AttendanceService::class)->clockInWindowFor($this->employee);

        $this->assertFalse($window['is_workday']);
        $this->assertSame('pending', $window['rest_day_request']['status']);
    }

    // --- expiry --------------------------------------------------------------------------

    public function test_a_request_older_than_a_day_can_no_longer_be_approved(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $request = $this->service()->create($this->employee, self::REST_DAY);

        // 25 hours later: approving now would back-date a shift nobody watched happen.
        $this->atManila('2026-07-05 09:00');

        try {
            $this->service()->approve($request->fresh(), $this->hrUser());
            $this->fail('A stale request should not be approvable.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('expired', $request->fresh()->status);
        $this->assertSame(0, Attendance::where('employee_id', $this->employee->id)->count());
    }

    // --- undoing -------------------------------------------------------------------------

    public function test_reopening_an_approval_removes_the_attendance_it_created(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $request = $this->service()->create($this->employee, self::REST_DAY);
        $this->service()->approve($request, $this->hrUser());

        $this->service()->reopen($request->fresh(), 'wrong person');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(
            0,
            Attendance::withTrashed()->where('employee_id', $this->employee->id)->count(),
            'A soft-deleted row would block the next approval — the unique index ignores deleted_at.',
        );
    }

    public function test_a_reopened_request_can_be_approved_again(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $request = $this->service()->create($this->employee, self::REST_DAY);
        $this->service()->approve($request, $this->hrUser());
        $this->service()->reopen($request->fresh(), 'checking');

        $this->service()->approve($request->fresh(), $this->hrUser());

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('08:00', $this->manilaTimeOf(
            Attendance::where('employee_id', $this->employee->id)->firstOrFail()->time_in,
        ));
    }

    public function test_a_worked_and_closed_shift_cannot_be_reopened(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $request = $this->service()->create($this->employee, self::REST_DAY);
        $this->service()->approve($request, $this->hrUser());

        $this->atManila(self::REST_DAY . ' 17:00');
        app(AttendanceService::class)->clockOut($this->employee);

        $this->expectException(ValidationException::class);
        $this->service()->reopen($request->fresh(), 'too late');
    }

    // --- scoping -------------------------------------------------------------------------

    public function test_an_employee_sees_only_their_own_requests(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $this->service()->create($this->employee, self::REST_DAY);

        $other = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
        $this->service()->create($other, self::REST_DAY);

        $this->assertCount(2, $this->service()->list($this->hrUser()));
        $this->assertCount(1, $this->service()->list($this->employee->user));
    }

    public function test_management_cannot_file_one(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $this->employee->update(['is_management' => true]);

        $this->expectException(ValidationException::class);
        $this->service()->create($this->employee->fresh(), self::REST_DAY);
    }

    public function test_a_cancelled_request_frees_the_day_to_be_asked_again(): void
    {
        $this->atManila(self::REST_DAY . ' 08:00');
        $request = $this->service()->create($this->employee, self::REST_DAY);
        $this->service()->cancel($request, $this->employee->user, 'changed my mind');

        $again = $this->service()->create($this->employee->fresh(), self::REST_DAY);

        $this->assertSame('pending', $again->status);
        $this->assertNotSame($request->id, $again->id);
        $this->assertSame('cancelled', RestDayRequest::find($request->id)->status);
    }
}
