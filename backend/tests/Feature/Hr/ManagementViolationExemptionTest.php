<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Management is exempt from the time clock, so the violation engine must never flag them —
 * and must not depend on `hr:generate-management-attendance` having written their rows to
 * stay correct. Before the guard, a month of un-generated rows escalated a manager from a
 * green UA straight to red AWOL while the roster showed them 'Present' on the same line.
 *
 * A rank-and-file control employee runs alongside, so a test that "passes" because the
 * recompute silently did nothing at all would still fail.
 */
class ManagementViolationExemptionTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');

        // Frozen clock: mid-June, so 06-01..06-16 are all past workdays.
        $this->travelTo(Carbon::parse('2026-06-17 12:00', $tz));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get(); // seeds the default ladder

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);

        $this->manager = $this->employee($dept->id, 'M-001', true);
        $this->staff = $this->employee($dept->id, 'E-001', false);

        // A human-entered row so the org's tracking start exists, as it does in a live DB —
        // without one, the absence floor would mask the bug rather than the guard fixing it.
        Attendance::create([
            'employee_id' => $this->staff->id, 'work_date' => '2026-06-01',
            'time_in' => Carbon::parse('2026-06-01 08:00', $tz)->utc(),
            'time_out' => Carbon::parse('2026-06-01 17:00', $tz)->utc(),
            'hours_worked' => 8, 'status' => 'present', 'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function employee(int $deptId, string $no, bool $isManagement): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $deptId,
            'employee_no' => $no,
            'job_title' => $isManagement ? 'Head' : 'Staff',
            'hire_date' => '2020-01-01',
            'base_salary' => $isManagement ? 90000 : 20000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => $isManagement,
            'work_schedule_id' => WorkSchedule::first()->id,
        ]);
    }

    public function test_management_is_never_flagged_when_their_rows_were_never_generated(): void
    {
        app(ViolationService::class)->recompute('2026-06');

        $this->assertSame(
            0,
            Violation::where('employee_id', $this->manager->id)->count(),
            'Management is exempt from the clock — a missing auto-generated row is not an absence.',
        );

        // The control: the same scan DID flag the rank-and-file employee for the days after
        // their one real record, so the assertion above is the guard working, not a no-op.
        $this->assertGreaterThan(
            0,
            Violation::where('employee_id', $this->staff->id)->count(),
            'The recompute should still flag a non-management employee for the same days.',
        );
    }

    public function test_management_is_not_flagged_late_on_a_row_that_says_late(): void
    {
        $tz = config('app.timezone_local');

        // A stray 'late' row — e.g. left behind by a clock-in from before they were made
        // management. The category is meaningless for someone exempt from the clock.
        Attendance::create([
            'employee_id' => $this->manager->id, 'work_date' => '2026-06-02',
            'time_in' => Carbon::parse('2026-06-02 09:30', $tz)->utc(),
            'time_out' => Carbon::parse('2026-06-02 17:00', $tz)->utc(),
            'hours_worked' => 6.5, 'status' => 'late', 'source' => Attendance::SOURCE_MANUAL,
        ]);

        app(ViolationService::class)->recompute('2026-06');

        $this->assertSame(0, Violation::where('employee_id', $this->manager->id)->count());
    }
}
