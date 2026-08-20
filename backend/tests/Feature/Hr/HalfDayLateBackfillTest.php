<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** The backfill that clears `late` marks already stored against approved half days. */
class HalfDayLateBackfillTest extends HrTestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-24';

    private const MIGRATION = __DIR__
        . '/../../../app/Modules/Hr/Database/Migrations/2026_08_19_000003_unmark_late_on_approved_half_days.php';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00',
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function runBackfill(): void
    {
        (require self::MIGRATION)->up();
    }

    /** A row as the OLD code would have written it: on time for the afternoon, stamped late. */
    private function lateRow(string $timeIn = '13:00', bool $override = false): Attendance
    {
        return Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'time_in' => Carbon::parse(self::DATE . " {$timeIn}", config('app.timezone_local'))->utc(),
            'time_out' => Carbon::parse(self::DATE . ' 17:00', config('app.timezone_local'))->utc(),
            'status' => 'late', 'status_override' => $override,
        ]);
    }

    private function approveHalfDay(string $period = 'AM'): void
    {
        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'period' => $period, 'reason' => 'errand', 'status' => 'approved',
        ]);
    }

    public function test_it_clears_a_late_mark_earned_by_arriving_on_time_for_the_afternoon(): void
    {
        $this->approveHalfDay();
        $row = $this->lateRow();

        $this->runBackfill();

        $this->assertSame('present', $row->refresh()->status);
    }

    public function test_it_honours_the_grace_period(): void
    {
        $this->approveHalfDay();
        $row = $this->lateRow('13:15');

        $this->runBackfill();

        $this->assertSame('present', $row->refresh()->status);
    }

    public function test_it_leaves_a_genuine_late_alone(): void
    {
        $this->approveHalfDay();
        $row = $this->lateRow('13:16');

        $this->runBackfill();

        $this->assertSame('late', $row->refresh()->status);
    }

    public function test_it_never_touches_a_day_with_no_half_day(): void
    {
        $row = $this->lateRow('09:00');

        $this->runBackfill();

        $this->assertSame('late', $row->refresh()->status);
    }

    public function test_it_leaves_a_status_hr_set_by_hand_alone(): void
    {
        $this->approveHalfDay();
        $row = $this->lateRow('13:00', override: true);

        $this->runBackfill();

        $this->assertSame('late', $row->refresh()->status);
    }

    public function test_a_pending_half_day_is_not_an_approved_one(): void
    {
        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'period' => 'AM', 'reason' => 'errand', 'status' => 'pending',
        ]);
        $row = $this->lateRow();

        $this->runBackfill();

        $this->assertSame('late', $row->refresh()->status);
    }

    public function test_it_removes_the_disciplinary_incident_the_wrong_status_produced(): void
    {
        $this->approveHalfDay();
        $this->lateRow();

        $unresolved = Violation::create([
            'employee_id' => $this->employee->id, 'type' => 'late', 'severity' => 'green',
            'work_date' => self::DATE, 'details' => 'Late arrival (#1 this month)', 'resolved' => false,
        ]);

        $this->runBackfill();

        $this->assertNull(Violation::find($unresolved->id));
    }

    public function test_it_keeps_an_incident_hr_has_already_actioned(): void
    {
        $this->approveHalfDay();
        $this->lateRow();

        // Resolved means HR had the conversation. Erasing that is not a migration's business,
        // even when the flag behind it should never have fired.
        $resolved = Violation::create([
            'employee_id' => $this->employee->id, 'type' => 'late', 'severity' => 'green',
            'work_date' => self::DATE, 'details' => 'Late arrival (#1 this month)', 'resolved' => true,
        ]);

        $this->runBackfill();

        $this->assertNotNull(Violation::find($resolved->id));
    }

    public function test_it_is_a_no_op_when_nobody_has_ever_filed_a_half_day(): void
    {
        $row = $this->lateRow('13:00');

        $this->runBackfill();

        $this->assertSame('late', $row->refresh()->status);
    }
}
