<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Models\WorkSuspension;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Support\InternDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * An intern's days, read off the attendance record the way anyone's are — before Intern Allowance
 * decides what any of them cost.
 *
 * The fortnight used: Mon 7 Sep (before hire) through Fri 18 Sep 2026, with "today" Wed 16 Sep.
 */
class InternDaysForTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $intern;

    /** @var array<string, InternDay> */
    private array $days;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 09:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00',
            'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $this->intern = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-OJT', 'job_title' => 'Intern', 'employment_status' => 'ojt',
            'hire_date' => '2026-09-08', 'base_salary' => 0, 'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);

        $this->punch('2026-09-08', '08:40', '17:00');   // late
        $this->punch('2026-09-10', '08:00', null);      // forgot to clock out
        $this->punch('2026-09-13', '09:00', '13:00', 0); // a Sunday, worked

        $vacation = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'days_per_year' => 15, 'is_paid' => true]);
        (new LeaveRequest)->forceFill([
            'employee_id' => $this->intern->id, 'leave_type_id' => $vacation->id,
            'start_date' => '2026-09-11', 'end_date' => '2026-09-11', 'is_half_day' => false,
            'days' => 1, 'reason' => 'Family trip', 'status' => 'approved',
        ])->save();

        WorkSuspension::create([
            'date' => '2026-09-14', 'name' => 'Typhoon', 'is_paid' => false,
            'work_requires_request' => false, 'declared_by' => User::factory()->create()->id,
        ]);

        (new Attendance)->forceFill([
            'employee_id' => $this->intern->id, 'work_date' => '2026-09-15',
            'status' => 'absent', 'status_override' => true, 'source' => Attendance::SOURCE_MANUAL,
        ])->save();

        $policy = app(AttendancePolicyService::class)->asOf('2026-09-18');
        $this->days = collect(app(AttendanceService::class)->internDaysFor($this->intern, '2026-09-07', '2026-09-18', $policy))
            ->keyBy('date')
            ->all();
    }

    private function punch(string $date, string $in, ?string $out, int $lunch = 60): void
    {
        $tz = config('app.timezone_local');

        Attendance::create([
            'employee_id' => $this->intern->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$in}", $tz)->utc(),
            'time_out' => $out === null ? null : Carbon::parse("{$date} {$out}", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => $lunch, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    public function test_days_before_the_hire_date_are_outside_employment(): void
    {
        $this->assertSame(InternDay::NOT_EMPLOYED, $this->days['2026-09-07']->kind);
    }

    public function test_a_worked_day_carries_its_minutes_measured_against_the_schedule(): void
    {
        $day = $this->days['2026-09-08'];

        $this->assertSame(InternDay::WORKED, $day->kind);
        $this->assertSame(40, $day->lateMinutes, 'past the 15-minute grace, measured from the 08:00 start');
        $this->assertSame(440, $day->workedMinutes, '08:40–17:00, less the 12:00–13:00 meal');
        $this->assertSame(480, $day->scheduledMinutes);
    }

    public function test_a_past_workday_with_no_record_is_an_absence_and_today_is_not_yet(): void
    {
        $this->assertSame(InternDay::ABSENT, $this->days['2026-09-09']->kind);
        $this->assertSame('No time in recorded', $this->days['2026-09-09']->detail);
        $this->assertSame(InternDay::PENDING, $this->days['2026-09-16']->kind);
        $this->assertSame(InternDay::PENDING, $this->days['2026-09-18']->kind);
    }

    /** The same reading everyone gets: an abandoned punch closes at the scheduled end, and is flagged. */
    public function test_a_missing_clock_out_is_read_as_it_is_for_employees(): void
    {
        $day = $this->days['2026-09-10'];

        $this->assertSame(InternDay::WORKED, $day->kind);
        $this->assertSame(480, $day->workedMinutes);
        $this->assertContains('missing_clockout', $day->flags);
    }

    public function test_approved_leave_is_leave_whatever_the_type_pays(): void
    {
        $day = $this->days['2026-09-11'];

        $this->assertSame(InternDay::LEAVE, $day->kind);
        $this->assertTrue($day->leavePaid);
        $this->assertSame('Vacation', $day->detail);
    }

    public function test_only_worked_rest_days_are_listed(): void
    {
        $this->assertArrayNotHasKey('2026-09-12', $this->days);
        $this->assertSame(InternDay::REST_DAY, $this->days['2026-09-13']->kind);
        $this->assertSame(240, $this->days['2026-09-13']->workedMinutes);
    }

    public function test_a_company_suspension_and_an_hr_override_are_read_as_declared(): void
    {
        $this->assertSame(InternDay::SUSPENSION, $this->days['2026-09-14']->kind);
        $this->assertFalse($this->days['2026-09-14']->suspensionPaid);

        $this->assertSame(InternDay::ABSENT, $this->days['2026-09-15']->kind);
        $this->assertTrue($this->days['2026-09-15']->byHr);
    }
}
