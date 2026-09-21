<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\InternAllowanceService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Support\InternAllowancePolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An intern may file leave — for the record and for HR's information — without credits, and
 * limited to the leave types Company → Intern Allowance allows. Payroll prices the day.
 */
class InternLeaveFilingTest extends HrTestCase
{
    use RefreshDatabase;

    private LeaveType $vacation;

    private LeaveType $sick;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00', config('app.timezone_local')));
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $this->vacation = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'days_per_year' => 15, 'is_paid' => true]);
        $this->sick = LeaveType::create(['name' => 'Sick', 'code' => 'SL', 'days_per_year' => 15, 'is_paid' => true]);

        // Nobody interning or regular here has a single credit.
        foreach (['ojt', 'regular'] as $status) {
            foreach ([$this->vacation, $this->sick] as $type) {
                LeaveTypeAllotment::create(['leave_type_id' => $type->id, 'employment_status' => $status, 'days_per_year' => 0]);
            }
        }
    }

    private function employee(string $status, string $email): Employee
    {
        return app(EmployeeService::class)->create([
            'name' => ucfirst($status) . ' Person',
            'email' => $email,
            'department_id' => $this->dept->id,
            'job_title' => 'Engineer',
            'employment_status' => $status,
            'hire_date' => '2026-06-01',
            'base_salary' => 0,
        ]);
    }

    private function file(Employee $employee, LeaveType $type): \App\Modules\Hr\Models\LeaveRequest
    {
        return app(LeaveService::class)->create($employee, [
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-09-22',
            'reason' => 'Family matter',
        ]);
    }

    private function onlyInternsMayFile(LeaveType ...$types): void
    {
        app(InternAllowanceService::class)->update(array_merge(InternAllowancePolicy::DEFAULTS, [
            'leave_types_allowed' => 'selected',
            'leave_type_ids' => array_map(fn (LeaveType $t) => $t->id, $types),
            'effective_from' => InternAllowanceService::OPENING_DATE,
        ]));
    }

    public function test_an_intern_is_offered_leave_types_without_holding_credits(): void
    {
        $intern = $this->employee('ojt', 'intern@example.com');

        $this->assertCount(2, app(LeaveService::class)->selectableTypesFor($intern));
    }

    public function test_the_rules_can_limit_which_types_an_intern_may_file(): void
    {
        $this->onlyInternsMayFile($this->sick);
        $intern = $this->employee('ojt', 'intern@example.com');

        $offered = app(LeaveService::class)->selectableTypesFor($intern);
        $this->assertSame([$this->sick->id], $offered->pluck('id')->all());

        $this->expectException(ValidationException::class);
        $this->file($intern, $this->vacation);
    }

    public function test_an_intern_files_and_is_approved_without_drawing_credits(): void
    {
        $intern = $this->employee('ojt', 'intern@example.com');

        $request = $this->file($intern, $this->vacation);
        $approved = app(LeaveService::class)->approve($request, User::factory()->create());

        $this->assertSame('approved', $approved->status);
        $this->assertSame('none', $approved->fresh()->deducted_from);
        $this->assertEquals(
            0,
            (float) LeaveBalance::where('employee_id', $intern->id)->where('leave_type_id', $this->vacation->id)->value('used_days'),
        );
    }

    /** The bypass is for interns only: a regular employee with no credits is still refused. */
    public function test_a_regular_employee_still_needs_the_credits(): void
    {
        $regular = $this->employee('regular', 'regular@example.com');

        $this->expectException(ValidationException::class);
        $this->file($regular, $this->vacation);
    }
}
