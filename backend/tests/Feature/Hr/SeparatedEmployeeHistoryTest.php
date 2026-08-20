<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/** A separation must not take a list endpoint down. */
class SeparatedEmployeeHistoryTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create(['name' => 'Ella Reyes'])->id,
            'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->leaveType = LeaveType::create([
            'name' => 'Emergency Leave', 'code' => 'EL', 'is_paid' => true, 'days_per_year' => 5,
        ]);

        $this->giveThemAHistory();
    }

    /** One row of every kind that points at an employee. */
    private function giveThemAHistory(): void
    {
        $id = $this->employee->id;

        LeaveRequest::create([
            'employee_id' => $id, 'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-05-04', 'end_date' => '2026-05-04', 'days' => 1,
            'reason' => 'Family emergency', 'status' => 'approved', 'reviewed_at' => now(),
        ]);

        HalfDayRequest::create([
            'employee_id' => $id, 'work_date' => '2026-05-05', 'period' => 'morning',
            'reason' => 'Clinic', 'status' => 'approved', 'reviewed_at' => now(),
        ]);

        EarlyOutRequest::create([
            'employee_id' => $id, 'work_date' => '2026-05-06', 'early_out_time' => '15:00',
            'reason' => 'Errand', 'status' => 'approved', 'reviewed_at' => now(),
        ]);

        OvertimeRequest::create([
            'employee_id' => $id, 'work_date' => '2026-05-07', 'hours' => 2,
            'time_from' => '17:00', 'time_to' => '19:00', 'reason' => 'Close',
            'status' => 'approved', 'reviewed_at' => now(),
        ]);

        Reimbursement::create([
            'employee_id' => $id, 'category' => 'supplies', 'amount' => 500,
            'description' => 'Printer ink', 'status' => 'pending', 'expense_date' => '2026-05-08',
        ]);

        DocumentRequest::create([
            'employee_id' => $id, 'type' => 'coe', 'reason' => 'Bank', 'status' => 'pending',
        ]);

        ChangeRequest::create([
            'employee_id' => $id, 'field' => 'phone', 'current_value' => '1', 'new_value' => '2',
            'reason' => 'New number', 'status' => 'pending',
        ]);

        PayslipRequest::create([
            'employee_id' => $id, 'period' => 'May 2026', 'reason' => 'Loan', 'status' => 'pending',
        ]);

        PayrollInquiry::create([
            'employee_id' => $id, 'subject' => 'Short pay', 'message' => 'Missing OT',
            'status' => 'pending',
        ]);

        EmployeeLoan::create([
            'employee_id' => $id, 'type' => 'loan', 'principal' => 12000,
            'per_period_amount' => 1000, 'start_date' => '2026-05-01', 'status' => 'requested',
            'requested_at' => now(),
        ]);

        $run = PayrollRun::create([
            'cycle_start' => '2026-05-01', 'cycle_end' => '2026-05-15', 'frequency' => 'semi_monthly',
            'type' => 'regular', 'status' => 'completed', 'run_at' => now(),
        ]);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $id,
            'base_salary' => 15000, 'gross' => 15000, 'total_deductions' => 0,
            'net_pay' => 15000, 'status' => 'disbursed',
        ]);
    }

    private function separate(): void
    {
        app(EmployeeService::class)->separate($this->employee, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-06-30',
        ]);
    }

    /**
     * Every list that can contain a leaver's row. A 500 here is the production failure.
     *
     * @return array<string, array{string}>
     */
    public static function listEndpoints(): array
    {
        return [
            'leave requests' => ['/api/v1/hr/leave-requests'],
            'half-day requests' => ['/api/v1/hr/half-day-requests'],
            'early-out requests' => ['/api/v1/hr/early-out-requests'],
            'overtime requests' => ['/api/v1/hr/overtime-requests'],
            'reimbursements' => ['/api/v1/hr/reimbursements'],
            'document requests' => ['/api/v1/hr/document-requests'],
            'change requests' => ['/api/v1/hr/change-requests'],
            'payslip requests' => ['/api/v1/hr/payslip-requests'],
            'payroll inquiries' => ['/api/v1/hr/payroll-inquiries'],
            'loans' => ['/api/v1/hr/loans'],
            'payroll runs' => ['/api/v1/hr/payroll/runs'],
            'notifications' => ['/api/v1/hr/me/notifications'],
            'dashboard' => ['/api/v1/hr/dashboard/stats'],
        ];
    }

    #[DataProvider('listEndpoints')]
    public function test_the_list_still_answers_after_a_separation(string $url): void
    {
        $this->separate();

        $this->actingAs($this->hrUser())->getJson($url)->assertOk();
    }

    /** The endpoint that actually went down, asserted on the row rather than the status. */
    public function test_a_leavers_leave_request_still_names_them(): void
    {
        $this->separate();

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/leave-requests')
            ->assertOk()
            ->assertJsonPath('data.0.employee.name', 'Ella Reyes')
            ->assertJsonPath('data.0.leave_type.name', 'Emergency Leave');
    }

    /** A payroll run's breakdown reads the name through the same relation. */
    public function test_a_completed_run_still_names_a_leaver(): void
    {
        $this->separate();

        $run = PayrollRun::firstOrFail();

        $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/payroll/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.breakdown.0.employee', 'Ella Reyes');
    }

    /**
     * A completed run is a historical fact. Its departmental totals were filtered through
     * `whereHas('employee')`, so a past period's payroll silently SHRANK when somebody resigned.
     */
    public function test_a_past_runs_department_total_does_not_change_when_someone_leaves(): void
    {
        $run = PayrollRun::firstOrFail();
        $dept = $this->employee->department_id;

        $before = $run->payslips()
            ->whereHas('employee', fn ($e) => $e->where('department_id', $dept))->sum('net_pay');

        $this->separate();

        $after = PayrollRun::findOrFail($run->id)->payslips()
            ->whereHas('employee', fn ($e) => $e->where('department_id', $dept))->sum('net_pay');

        $this->assertSame(
            (float) $before,
            (float) $after,
            'a period already paid cannot change because someone left afterwards',
        );
    }

    /** The employee's own history stays readable to them too. */
    public function test_the_employees_own_record_still_resolves(): void
    {
        $this->separate();

        $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/employees/{$this->employee->id}")
            ->assertOk()
            ->assertJsonPath('data.is_separated', true);
    }

    /**
     * Deleting a LEAVE TYPE is the same shape of failure through a different relation, and its
     * scoping is deliberate (LeaveService filters balances by `whereHas('leaveType')` so a
     * deleted type's rows vanish and return if it is restored). So the resource degrades rather
     * than the relation widening.
     */
    public function test_deleting_a_leave_type_does_not_break_the_leave_list(): void
    {
        $this->leaveType->delete();

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/leave-requests')
            ->assertOk();
    }
}
