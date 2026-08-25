<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Switching the attendance exemption on and off. The flag alone says only what someone is
 * TODAY, so every history-scanning consumer needs to know WHEN it started — without that, a
 * promotion silently erased the lates and absences the employee accrued before it.
 */
class ManagementFlagLifecycleTest extends HrTestCase
{
    use RefreshDatabase;

    private Department $dept;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone_local');
        $this->travelTo(Carbon::parse('2026-06-17 12:00', $this->tz));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get(); // seeds the default ladder

        $this->dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function employee(string $no, bool $isManagement = false): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->dept->id,
            'employee_no' => $no,
            'job_title' => 'Staff',
            'hire_date' => '2020-01-01',
            'base_salary' => 40000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => $isManagement,
            'management_since' => $isManagement ? '2020-01-01' : null,
            'work_schedule_id' => WorkSchedule::first()->id,
        ]);
    }

    private function punch(Employee $employee, string $date, string $time, string $status): Attendance
    {
        return Attendance::create([
            'employee_id' => $employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$time}", $this->tz)->utc(),
            'time_out' => Carbon::parse("{$date} 17:00", $this->tz)->utc(),
            'hours_worked' => 8, 'status' => $status, 'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    public function test_switching_the_flag_on_stamps_the_date_it_started(): void
    {
        $employee = $this->employee('E-001');

        app(EmployeeService::class)->update($employee, ['is_management' => true]);

        $employee->refresh();

        $this->assertTrue($employee->is_management);
        $this->assertSame('2026-06-17', $employee->management_since?->toDateString());
    }

    public function test_a_new_hire_flagged_management_is_exempt_from_their_first_day(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class); // create() assigns the hr_employee role

        $employee = app(EmployeeService::class)->create([
            'name' => 'Nadia Lim', 'email' => 'nadia@viteseo.test',
            'department_id' => $this->dept->id, 'job_title' => 'Head of Ops',
            'hire_date' => '2026-06-01', 'base_salary' => 120000, 'is_management' => true,
        ]);

        // Not 2026-06-17: they were exempt from the day they joined, not the day HR typed it.
        $this->assertSame('2026-06-01', $employee->management_since?->toDateString());
    }

    public function test_a_promotion_does_not_erase_the_record_built_before_it(): void
    {
        $employee = $this->employee('E-002');

        // Three real lates as rank-and-file, then the promotion.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->punch($employee, $date, '09:30', 'late');
        }

        app(EmployeeService::class)->update($employee, ['is_management' => true]);

        app(ViolationService::class)->recompute('2026-06');

        $before = Violation::where('employee_id', $employee->id)
            ->whereDate('work_date', '<', '2026-06-17')
            ->count();
        $after = Violation::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', '2026-06-17')
            ->count();

        $this->assertGreaterThan(0, $before, 'The lates they actually accrued must survive the promotion.');
        $this->assertSame(0, $after, 'From the promotion onward they are exempt from the clock.');
    }

    public function test_an_employee_flagged_since_hire_is_exempt_throughout(): void
    {
        $manager = $this->employee('M-001', isManagement: true);
        $control = $this->employee('E-003');

        app(ViolationService::class)->recompute('2026-06');

        $this->assertSame(0, Violation::where('employee_id', $manager->id)->count());
        // The control proves the scan ran at all rather than quietly doing nothing.
        $this->assertGreaterThan(0, Violation::where('employee_id', $control->id)->count());
    }

    public function test_switching_the_flag_off_clears_today_forward_and_keeps_the_history(): void
    {
        $employee = $this->employee('M-002', isManagement: true);

        $past = Attendance::create([
            'employee_id' => $employee->id, 'work_date' => '2026-06-16',
            'time_in' => Carbon::parse('2026-06-16 08:00', $this->tz)->utc(),
            'time_out' => Carbon::parse('2026-06-16 17:00', $this->tz)->utc(),
            'hours_worked' => 8, 'status' => 'present', 'source' => Attendance::SOURCE_SYSTEM,
        ]);

        // Written by last night's job, before HR changed their mind this morning.
        $today = Attendance::create([
            'employee_id' => $employee->id, 'work_date' => '2026-06-17',
            'time_in' => Carbon::parse('2026-06-17 08:00', $this->tz)->utc(),
            'time_out' => Carbon::parse('2026-06-17 17:00', $this->tz)->utc(),
            'hours_worked' => 8, 'status' => 'present', 'source' => Attendance::SOURCE_SYSTEM,
        ]);

        app(EmployeeService::class)->update($employee, ['is_management' => false]);

        $employee->refresh();

        $this->assertFalse($employee->is_management);
        $this->assertNull($employee->management_since);
        // Today's synthetic row would read to the time clock as already-clocked-in.
        $this->assertSoftDeleted('hr_attendance', ['id' => $today->id]);
        // The days they WERE exempt for stay — deleting them turns each into an absence.
        $this->assertDatabaseHas('hr_attendance', ['id' => $past->id, 'deleted_at' => null]);
    }

    public function test_switching_the_flag_off_leaves_real_punches_alone(): void
    {
        $employee = $this->employee('M-003', isManagement: true);
        $manual = $this->punch($employee, '2026-06-17', '08:00', 'present');

        app(EmployeeService::class)->update($employee, ['is_management' => false]);

        $this->assertDatabaseHas('hr_attendance', ['id' => $manual->id, 'deleted_at' => null]);
    }

    public function test_an_unrelated_save_never_moves_the_exemption_date(): void
    {
        $employee = $this->employee('M-004', isManagement: true);

        app(EmployeeService::class)->update($employee, ['job_title' => 'Head of Ops']);

        $employee->refresh();

        $this->assertSame('2020-01-01', $employee->management_since?->toDateString());
        $this->assertSame('Head of Ops', $employee->job_title);
    }
}
