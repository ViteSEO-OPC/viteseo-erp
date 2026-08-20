<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Models\WorkSuspension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The employee's own monthly attendance record (GET /api/v1/hr/me/attendance-report).
 *
 * The screen exists so an employee can check the record payroll will be run against, so the
 * tests pin the two things that would make it useless: that the day classifications agree with
 * the roster HR sees, and that the endpoint answers about the CALLER and nobody else.
 *
 * Clock parked at 2026-08-20 12:00 Manila; the schedule is 08:00–17:00 Mon–Fri with no grace.
 */
class MyAttendanceReportTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 0,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->user = User::factory()->create();
        $this->employee = Employee::create([
            'user_id' => $this->user->id,
            'department_id' => $department->id,
            'employee_no' => 'E-MAR-100',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    /** A worked day: in and out at the given Manila times. */
    private function punch(string $date, string $in, ?string $out, string $status = 'present', ?Employee $employee = null): Attendance
    {
        $tz = config('app.timezone_local');

        return Attendance::create([
            'employee_id' => ($employee ?? $this->employee)->id,
            'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$in}", $tz)->utc(),
            'time_out' => $out === null ? null : Carbon::parse("{$date} {$out}", $tz)->utc(),
            'status' => $status,
            'hours_worked' => 8,
        ]);
    }

    /** @return array<string, mixed> the `data` payload for a month */
    private function report(?string $month = null, ?User $as = null): array
    {
        Sanctum::actingAs($as ?? $this->user);

        $response = $this->getJson('/api/v1/hr/me/attendance-report' . ($month ? "?month={$month}" : ''));
        $response->assertOk();

        return $response->json('data');
    }

    /** @param array<string, mixed> $data */
    private function day(array $data, string $date): array
    {
        foreach ($data['days'] as $day) {
            if ($day['date'] === $date) {
                return $day;
            }
        }

        $this->fail("No day {$date} in the report.");
    }

    // --- the period it answers about ---

    public function test_it_defaults_to_the_current_manila_month(): void
    {
        $data = $this->report();

        $this->assertSame('2026-08', $data['selected_month']['value']);
        $this->assertSame(2026, $data['selected_year']);
        $this->assertTrue($data['month_in_progress']);
        $this->assertCount(31, $data['days']);
        // Twelve months of the selected year, so the picker and the breakdown are one list.
        $this->assertCount(12, $data['months']);
    }

    public function test_a_malformed_month_is_rejected_rather_than_parsed(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/hr/me/attendance-report?month=2026-13')->assertStatus(422);
    }

    // --- the day ladder ---

    public function test_a_late_arrival_reports_its_minutes_and_a_full_day_reports_none(): void
    {
        // Mon 2026-08-03: in at 08:45 against an 08:00 start with no grace.
        $this->punch('2026-08-03', '08:45', '17:00', 'late');
        $this->punch('2026-08-04', '08:00', '17:00');

        $data = $this->report('2026-08');

        $this->assertSame('late', $this->day($data, '2026-08-03')['status']);
        $this->assertSame(45, $this->day($data, '2026-08-03')['late_minutes']);
        $this->assertSame(0, $this->day($data, '2026-08-04')['late_minutes']);
        $this->assertSame(45, $data['totals']['late_minutes']);
        $this->assertSame(1, $data['totals']['late']);
    }

    public function test_leaving_before_the_scheduled_end_reports_undertime(): void
    {
        // Out at 15:30 against a 17:00 end — 90 minutes short.
        $this->punch('2026-08-05', '08:00', '15:30');

        $data = $this->report('2026-08');

        $this->assertSame(90, $this->day($data, '2026-08-05')['undertime_minutes']);
        $this->assertSame(90, $data['totals']['undertime_minutes']);
    }

    public function test_a_missing_clock_out_is_not_reported_as_undertime(): void
    {
        // An abandoned punch is auto-closed at the scheduled end, never treated as leaving early.
        $this->punch('2026-08-06', '08:00', null);

        $data = $this->report('2026-08');

        $this->assertSame(0, $this->day($data, '2026-08-06')['undertime_minutes']);
    }

    public function test_a_scheduled_workday_with_no_punch_is_absent_and_today_is_not(): void
    {
        $data = $this->report('2026-08');

        // Mon 2026-08-03 is past and empty; today (the 20th) is still open.
        $this->assertSame('absent', $this->day($data, '2026-08-03')['status']);
        $this->assertSame('upcoming', $this->day($data, '2026-08-20')['status']);
        $this->assertSame('rest_day', $this->day($data, '2026-08-01')['status']);
    }

    public function test_approved_leave_covers_its_days_and_is_totalled_by_type(): void
    {
        $type = LeaveType::create([
            'name' => 'Annual Leave', 'code' => 'AL', 'days_per_year' => 15, 'is_paid' => true,
        ]);

        LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-11',
            'days' => 2,
            'reason' => 'Trip',
            'status' => 'approved',
        ]);

        $data = $this->report('2026-08');

        $this->assertSame('on_leave', $this->day($data, '2026-08-10')['status']);
        $this->assertSame('Annual Leave', $this->day($data, '2026-08-10')['leave_type']);
        $this->assertTrue($this->day($data, '2026-08-10')['leave_is_paid']);
        $this->assertSame(2, $data['totals']['on_leave']);
        $this->assertSame([['name' => 'Annual Leave', 'days' => 2]], $data['totals']['leave_by_type']);
    }

    public function test_a_non_working_holiday_is_named_and_is_never_an_absence(): void
    {
        $set = HolidaySet::create(['country_code' => 'PH', 'year' => 2026, 'source' => 'manual']);
        Holiday::create([
            'holiday_set_id' => $set->id,
            'name' => 'Ninoy Aquino Day',
            'date' => '2026-08-12',
            'type' => 'regular',
        ]);

        $day = $this->day($this->report('2026-08'), '2026-08-12');

        $this->assertSame('holiday', $day['status']);
        $this->assertSame('Ninoy Aquino Day', $day['holiday']['name']);
    }

    public function test_a_suspended_workday_reads_as_suspended_rather_than_absent(): void
    {
        WorkSuspension::create([
            'date' => '2026-08-13',
            'name' => 'Typhoon Egay',
            'is_paid' => true,
            'declared_by' => $this->hrUser()->id,
        ]);

        $data = $this->report('2026-08');
        $day = $this->day($data, '2026-08-13');

        $this->assertSame('suspended_paid', $day['status']);
        $this->assertSame('Typhoon Egay', $day['suspension']['name']);
        $this->assertSame(1, $data['totals']['suspended']);
    }

    public function test_approved_overtime_backed_by_a_punch_is_reported(): void
    {
        // Worked 08:00–19:00 on Mon 2026-08-17 with 18:00–20:00 approved: the overlap is 60 min.
        $this->punch('2026-08-17', '08:00', '19:00');
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-17',
            'hours' => 2,
            'time_from' => '18:00',
            'time_to' => '20:00',
            'reason' => 'Release',
            'status' => 'approved',
        ]);

        $data = $this->report('2026-08');

        $this->assertSame(60, $this->day($data, '2026-08-17')['overtime_minutes']);
        $this->assertSame(60, $data['totals']['overtime_minutes']);
    }

    // --- whose record it is ---

    public function test_it_reports_the_callers_own_days_and_not_a_colleagues(): void
    {
        $other = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-MAR-200',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);

        $this->punch('2026-08-03', '10:30', '17:00', 'late', $other);

        $data = $this->report('2026-08');

        // The colleague's lateness must not appear on this employee's record.
        $this->assertSame('absent', $this->day($data, '2026-08-03')['status']);
        $this->assertSame(0, $data['totals']['late_minutes']);
        $this->assertSame($this->user->name, $data['employee']['name']);
    }

    public function test_a_user_with_no_employee_record_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/hr/me/attendance-report')->assertStatus(403);
    }

    // --- the year around the month ---

    public function test_every_month_of_the_year_carries_its_own_totals(): void
    {
        $this->punch('2026-07-06', '09:00', '17:00', 'late');

        $data = $this->report('2026-08');

        $july = collect($data['months'])->firstWhere('value', '2026-07');
        $august = collect($data['months'])->firstWhere('value', '2026-08');

        $this->assertSame(60, $july['totals']['late_minutes']);
        $this->assertSame(0, $august['totals']['late_minutes']);
        // The year total spans both, so the breakdown and the headline cannot drift.
        $this->assertSame(60, $data['year_totals']['late_minutes']);
    }

    public function test_days_before_the_hire_date_are_not_absences(): void
    {
        $this->employee->update(['hire_date' => '2026-08-17']);

        $data = $this->report('2026-08');

        $this->assertSame('not_employed', $this->day($data, '2026-08-03')['status']);
        // Only the 17th, 18th and 19th were workdays they were employed for and did not punch.
        $this->assertSame(3, $data['totals']['absent']);
    }
}
