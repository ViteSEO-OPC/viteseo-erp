<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Models\WorkSuspension;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\WorkRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Work requests — permission to work a day nobody was expected in on.
 *
 * A Mon–Fri employee who comes in on a Saturday cannot simply clock in: the day earns a
 * rest-day premium, so somebody has to agree to spend it. Pressing Time in files a request
 * stamped with that moment, and HR's approval writes the attendance row AT that moment —
 * the promise the whole feature rests on, and the first thing these tests pin down.
 *
 * Two switches decide whether the gate applies at all: the company rule
 * `rest_day_work_requires_request`, and a work suspension's own `work_requires_request`. The
 * last two sections here pin both, including the thing that would be easiest to get wrong —
 * that a rest day walked straight onto with the gate off is `present`, never `late`.
 */
class WorkRequestTest extends HrTestCase
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

    private function service(): WorkRequestService
    {
        return app(WorkRequestService::class);
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

    public function test_an_ordinary_workday_cannot_be_filed_as_a_work_request(): void
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
        $this->assertSame('rest_day', $window['work_request_required']);
        $this->assertSame('pending', $window['work_request']['status']);
        $this->assertSame('rest_day', $window['work_request']['type']);
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
        $this->assertSame('cancelled', WorkRequest::find($request->id)->status);
    }

    // --- the company rule: rest-day work without asking ---------------------------------

    private function setRestDayGate(bool $on): void
    {
        // Written through a throwaway instance: nothing here is bound as a singleton, so the
        // AttendancePolicyService the next app() call builds starts with an empty memo and
        // reads the new value off the table.
        app(AttendancePolicyService::class)->get()
            ->update(['rest_day_work_requires_request' => $on]);
    }

    public function test_with_the_gate_off_a_rest_day_is_clocked_in_directly(): void
    {
        $this->setRestDayGate(false);
        $this->atManila(self::REST_DAY . ' 13:30');

        $att = app(AttendanceService::class)->clockIn($this->employee->fresh());

        $this->assertSame('13:30', $this->manilaTimeOf($att->time_in));
        $this->assertSame(0, WorkRequest::count(), 'Nothing should be queued when nobody has to ask.');
    }

    public function test_a_rest_day_walked_straight_onto_is_never_late(): void
    {
        $this->setRestDayGate(false);
        // Hours past the 09:00 default start. There is no shift on a Saturday to be late for,
        // and judging this against the schedule's fallback start is the bug being pinned.
        $this->atManila(self::REST_DAY . ' 15:00');

        $att = app(AttendanceService::class)->clockIn($this->employee->fresh());

        $this->assertSame('present', $att->status);
    }

    public function test_with_the_gate_off_there_is_nothing_to_file(): void
    {
        $this->setRestDayGate(false);
        $this->atManila(self::REST_DAY . ' 08:00');

        $this->expectException(ValidationException::class);
        $this->service()->create($this->employee->fresh(), self::REST_DAY);
    }

    // --- suspensions that require permission ---------------------------------------------

    /** A Friday — an ordinary workday for this Mon–Fri employee. */
    private const SUSPENDED_DAY = '2026-07-03';

    private function suspend(bool $requiresRequest): void
    {
        WorkSuspension::create([
            'date' => self::SUSPENDED_DAY,
            'name' => 'Typhoon Egay',
            'is_paid' => true,
            'work_requires_request' => $requiresRequest,
        ]);
    }

    public function test_an_ordinary_suspension_still_lets_the_skeleton_crew_time_in(): void
    {
        $this->suspend(requiresRequest: false);
        $this->atManila(self::SUSPENDED_DAY . ' 08:30');

        $att = app(AttendanceService::class)->clockIn($this->employee->fresh());

        $this->assertSame('08:30', $this->manilaTimeOf($att->time_in));
        $this->assertSame(0, WorkRequest::count());
    }

    public function test_a_suspension_that_requires_permission_refuses_the_punch(): void
    {
        $this->suspend(requiresRequest: true);
        $this->atManila(self::SUSPENDED_DAY . ' 08:30');

        try {
            app(AttendanceService::class)->clockIn($this->employee->fresh());
            $this->fail('The punch should have been refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_suspension_request_is_filed_as_its_own_type_and_approves_at_the_press(): void
    {
        $this->suspend(requiresRequest: true);
        $this->atManila(self::SUSPENDED_DAY . ' 08:30');

        $request = $this->service()->create($this->employee->fresh(), self::SUSPENDED_DAY);
        $this->assertSame(WorkRequest::TYPE_SUSPENSION, $request->type);

        // HR gets to it after lunch; the employee keeps the morning they actually worked.
        $this->atManila(self::SUSPENDED_DAY . ' 14:00');
        $this->service()->approve($request->fresh(), $this->hrUser());

        $att = Attendance::where('employee_id', $this->employee->id)
            ->where('work_date', self::SUSPENDED_DAY)
            ->firstOrFail();

        $this->assertSame('08:30', $this->manilaTimeOf($att->time_in));
        // Work was cancelled — there is no shift left to be late against, even though the
        // day is a scheduled workday and 08:30 is past the 09:00 start plus grace.
        $this->assertSame('present', $att->status);
    }

    public function test_a_rest_day_that_is_also_suspended_is_still_a_rest_day_request(): void
    {
        // The employee was never expected in, so the company cancelling work says nothing
        // about them — and the day still earns a rest-day premium, not an ordinary one.
        WorkSuspension::create([
            'date' => self::REST_DAY,
            'name' => 'Typhoon Egay',
            'is_paid' => true,
            'work_requires_request' => true,
        ]);
        $this->atManila(self::REST_DAY . ' 08:00');

        $this->assertSame(
            WorkRequest::TYPE_REST_DAY,
            $this->service()->create($this->employee->fresh(), self::REST_DAY)->type,
        );
    }

    public function test_the_panel_reports_a_suspension_that_requires_permission(): void
    {
        $this->suspend(requiresRequest: true);
        $this->atManila(self::SUSPENDED_DAY . ' 08:30');

        $window = app(AttendanceService::class)->clockInWindowFor($this->employee->fresh());

        $this->assertTrue($window['is_workday']);
        $this->assertSame(WorkRequest::TYPE_SUSPENSION, $window['work_request_required']);
        $this->assertTrue($window['suspension']['work_requires_request']);
    }
}
