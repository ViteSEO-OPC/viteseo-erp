<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Requisition;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\InternAllowanceService;
use App\Modules\Hr\Services\RecruitmentService;
use App\Modules\Hr\Support\InternAllowancePolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * An intern's own allowance: carried from the requisition they were hired off, set by HR, and
 * gone the moment there is no internship for it to describe.
 */
class InternAllowanceEmployeeTest extends HrTestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00', config('app.timezone_local')));
        // EmployeeService::create() assigns the 'hr_employee' role to every new hire.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
    }

    private function recruitment(): RecruitmentService
    {
        return app(RecruitmentService::class);
    }

    /** Set the company's calculation method (and nothing else) from the opening date onward. */
    private function companyCalculates(string $method): void
    {
        app(InternAllowanceService::class)->update(array_merge(InternAllowancePolicy::DEFAULTS, [
            'method' => $method,
            'effective_from' => InternAllowanceService::OPENING_DATE,
        ]));
    }

    private function requisition(string $employmentType, ?float $amount = null, ?string $period = null): Requisition
    {
        return $this->recruitment()->createRequisition([
            'title' => 'Software Engineer Intern',
            'employment_type' => $employmentType,
            'department_id' => $this->dept->id,
            'openings' => 1,
            'stage' => 'active',
            'allowance_amount' => $amount,
            'allowance_period' => $period,
            'salary_range' => $employmentType === 'ojt' ? null : '₱30,000',
        ]);
    }

    private function hireFrom(Requisition $req): Employee
    {
        $candidate = $this->recruitment()->createCandidate([
            'requisition_id' => $req->id,
            'name' => 'Kim Reyes',
            'email' => 'kim.reyes@example.com',
            'source' => 'direct',
        ]);

        return Employee::findOrFail($this->recruitment()->hire($candidate)->hired_employee_id);
    }

    private function employee(string $status, ?float $allowance = null): Employee
    {
        return app(EmployeeService::class)->create([
            'name' => 'Lia Tan',
            'email' => 'lia.tan@example.com',
            'department_id' => $this->dept->id,
            'job_title' => 'Software Engineer Intern',
            'employment_status' => $status,
            'hire_date' => '2026-06-01',
            'base_salary' => 0,
            'allowance_amount' => $allowance,
        ]);
    }

    // --- the handoff from recruitment ---------------------------------------------------------

    public function test_hiring_an_intern_carries_the_requisition_allowance_when_the_units_agree(): void
    {
        $employee = $this->hireFrom($this->requisition('ojt', 650, 'per_day'));

        $this->assertEquals(650, (float) $employee->allowance_amount);
    }

    public function test_an_hourly_requisition_carries_under_hourly_rules(): void
    {
        $this->companyCalculates('per_hour');

        $employee = $this->hireFrom($this->requisition('ojt', 80, 'per_hour'));

        $this->assertEquals(80, (float) $employee->allowance_amount);
    }

    /** ₱8,000 a month read as ₱8,000 a day would be wrong by a factor of twenty. */
    public function test_a_requisition_quoted_in_another_unit_is_not_carried(): void
    {
        $employee = $this->hireFrom($this->requisition('ojt', 8000, 'per_month'));

        $this->assertSame('ojt', $employee->employment_status);
        $this->assertNull($employee->allowance_amount);
    }

    public function test_a_standard_hire_has_no_allowance(): void
    {
        $employee = $this->hireFrom($this->requisition('full_time'));

        $this->assertNull($employee->allowance_amount);
    }

    // --- HR sets it --------------------------------------------------------------------------------

    public function test_hr_can_set_an_interns_own_allowance_and_required_hours(): void
    {
        $intern = $this->employee('ojt');

        $this->actingAsHr()->patchJson("/api/v1/hr/employees/{$intern->id}", [
            'allowance_amount' => 700,
            'ojt_required_hours' => 486,
        ])
            ->assertOk()
            ->assertJsonPath('data.allowance_amount', 700)
            ->assertJsonPath('data.ojt_required_hours', 486);
    }

    public function test_someone_who_is_not_an_intern_cannot_be_given_an_allowance(): void
    {
        $employee = $this->employee('probationary');

        $this->actingAsHr()->patchJson("/api/v1/hr/employees/{$employee->id}", [
            'allowance_amount' => 700,
            'ojt_required_hours' => 486,
        ])->assertStatus(422)->assertJsonValidationErrors(['allowance_amount', 'ojt_required_hours']);
    }

    /** Judged against the status the same save leaves them with. */
    public function test_an_allowance_can_be_set_in_the_same_save_that_makes_someone_an_intern(): void
    {
        $employee = $this->employee('probationary');

        $this->actingAsHr()->patchJson("/api/v1/hr/employees/{$employee->id}", [
            'employment_status' => 'ojt',
            'allowance_amount' => 700,
        ])->assertOk()->assertJsonPath('data.allowance_amount', 700);
    }

    // --- and it goes when the internship does ---------------------------------------------------

    public function test_editing_the_status_away_from_intern_drops_the_allowance(): void
    {
        $intern = $this->employee('ojt', 650);

        $this->actingAsHr()->patchJson("/api/v1/hr/employees/{$intern->id}", [
            'employment_status' => 'regular',
        ])->assertOk();

        $this->assertNull($intern->fresh()->allowance_amount);
    }

    /**
     * The allowance stays: a conversion dated inside a pay period still pays the days before it as
     * an internship, and at this intern's own rate. The conversion date is what says which days.
     */
    public function test_conversion_records_when_the_internship_ended_and_keeps_its_rate(): void
    {
        $intern = $this->employee('ojt', 650);

        $converted = app(EmployeeService::class)->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-01',
            'base_salary' => 32000,
        ]);

        $this->assertEquals(650, (float) $converted->allowance_amount);
        $this->assertSame('2026-09-01', $converted->intern_converted_on->toDateString());
        $this->assertTrue($converted->isInternOn('2026-08-31'));
        $this->assertFalse($converted->isInternOn('2026-09-01'));
    }
}
