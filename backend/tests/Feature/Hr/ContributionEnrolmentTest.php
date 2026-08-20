<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** un-enrolling an employee from a government contribution. */
class ContributionEnrolmentTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // The checklist back-fills a row per ACTIVE contribution, and the rates the preview shows
        // come from the dated versions — both live in this seeder.
        $this->seed(ContributionSettingSeeder::class);

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function url(): string
    {
        return "/api/v1/hr/employees/{$this->employee->id}/benefits";
    }

    public function test_everyone_starts_enrolled_in_every_contribution(): void
    {
        $this->actingAsHr()->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertSame(3, EmployeeBenefit::where('employee_id', $this->employee->id)
            ->where('enrolled', true)->count());
    }

    public function test_an_employee_can_be_un_enrolled(): void
    {
        $this->actingAsHr()->getJson($this->url())->assertOk(); // back-fill the rows

        $this->actingAsHr()->putJson($this->url(), [
            'benefits' => [['contribution_type' => 'sss', 'enrolled' => false]],
        ])->assertOk();

        $this->assertDatabaseHas('hr_employee_benefits', [
            'employee_id' => $this->employee->id, 'contribution_type' => 'sss', 'enrolled' => false,
        ]);
    }

    /** Un-enrolling one contribution leaves the others alone. */
    /** The employee's own Compensation tab renders this block, and the HR reader is hr_officer-only. */
    public function test_an_employee_can_read_their_own_enrolment(): void
    {
        $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/me/benefits')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /** Identity comes from the token: the route takes no id, so none can be substituted. */
    public function test_the_self_service_read_is_scoped_to_the_caller(): void
    {
        $other = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
        $this->actingAsHr()->putJson("/api/v1/hr/employees/{$other->id}/benefits", [
            'benefits' => [['contribution_type' => 'sss', 'enrolled' => false]],
        ])->assertOk();

        $rows = collect($this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/me/benefits')->assertOk()->json('data'))
            ->keyBy('contribution_type');

        $this->assertTrue($rows['sss']['enrolled'], "the other employee's un-enrolment, not theirs");
    }

    /** Read only. The write path stays hr_officer-only. */
    public function test_an_employee_still_cannot_change_their_own_enrolment(): void
    {
        $this->actingAs($this->employee->user)
            ->putJson($this->url(), [
                'benefits' => [['contribution_type' => 'sss', 'enrolled' => false]],
            ])
            ->assertForbidden();

        $this->assertTrue(
            EmployeeBenefit::where('employee_id', $this->employee->id)
                ->where('contribution_type', 'sss')->value('enrolled') ?? true,
        );
    }

    public function test_un_enrolling_one_contribution_leaves_the_others_enrolled(): void
    {
        $this->actingAsHr()->getJson($this->url())->assertOk();
        $this->actingAsHr()->putJson($this->url(), [
            'benefits' => [['contribution_type' => 'sss', 'enrolled' => false]],
        ])->assertOk();

        $rows = collect($this->actingAsHr()->getJson($this->url())->assertOk()->json('data'))
            ->keyBy('contribution_type');

        $this->assertFalse($rows['sss']['enrolled']);
        $this->assertTrue($rows['philhealth']['enrolled']);
        $this->assertTrue($rows['pagibig']['enrolled']);
    }

    /** THE BUG: `['required','string']` accepted anything, so a typo saved a row matching nothing. */
    public function test_an_unknown_contribution_type_is_rejected(): void
    {
        $this->actingAsHr()->putJson($this->url(), [
            'benefits' => [['contribution_type' => 'sss_', 'enrolled' => false]],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('hr_employee_benefits', ['contribution_type' => 'sss_']);
    }

    public function test_the_audit_line_names_what_changed(): void
    {
        $this->actingAsHr()->getJson($this->url())->assertOk();
        $this->actingAsHr()->putJson($this->url(), [
            'benefits' => [['contribution_type' => 'pagibig', 'enrolled' => false]],
        ])->assertOk();

        $log = AuditLog::where('module', 'Benefits')->latest('id')->firstOrFail();

        // "Updated contribution enrolment for X" could not answer "who stopped their Pag-IBIG?".
        $this->assertStringContainsString('PAGIBIG', $log->description);
        $this->assertStringContainsString('no longer deducted', $log->description);
    }

    public function test_an_employee_cannot_change_their_own_enrolment(): void
    {
        $this->actingAs($this->employee->user)->putJson($this->url(), [
            'benefits' => [['contribution_type' => 'sss', 'enrolled' => false]],
        ])->assertForbidden();
    }
}
