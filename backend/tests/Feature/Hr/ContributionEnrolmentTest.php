<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B9 item 3 — un-enrolling an employee from a government contribution.
 *
 * WHY IT MATTERS. Everyone was enrolled in everything with no way to say otherwise, so the only
 * way to express "this person is exempt" was a peso override on their profile — which replaces the
 * employee half alone and leaves a remittance pair that exists on no agency table (B7). Un-enrolling
 * takes BOTH halves off, which is the thing an exemption actually means.
 */
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

    /** Not deducted means not deducted — the employer half goes too, unlike an override. */
    public function test_an_un_enrolled_contribution_charges_neither_half(): void
    {
        $this->actingAsHr()->getJson($this->url())->assertOk();
        $this->actingAsHr()->putJson($this->url(), [
            'benefits' => [['contribution_type' => 'sss', 'enrolled' => false]],
        ])->assertOk();

        $rows = collect($this->actingAsHr()->getJson("/api/v1/hr/employees/{$this->employee->id}/statutory-preview")
            ->assertOk()->json('data'));

        $sss = $rows->firstWhere('type', 'sss');
        $this->assertFalse($sss['enrolled']);
        $this->assertSame(0.0, (float) $sss['calculated_employee']);
        $this->assertSame(0.0, (float) $sss['calculated_employer']);

        // The others are untouched.
        $this->assertTrue($rows->firstWhere('type', 'philhealth')['enrolled']);
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
