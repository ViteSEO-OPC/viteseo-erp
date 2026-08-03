<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AllowanceService;
use App\Modules\Hr\Services\BenefitProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B14 — paying one employee a different amount.
 *
 * THE LINE THIS SLICE WALKS. B1 gave every enrolment its own amount via a migration, so editing an
 * allowance on the Benefits page reached nobody and the page drove no one's pay; B6 removed the
 * mechanism outright. B14 brings it back for the case it was always meant for — a genuine
 * arrangement with one person — and everything here exists to keep the two apart:
 *
 *   - enrolling somebody NEVER writes an amount
 *   - an amount is stamped with who set it and when
 *   - a programme edit reaches everyone following it, and nobody who is not
 */
class AllowanceOverrideTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Employee $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = $this->employee('E-001', $dept->id);
        $this->colleague = $this->employee('E-002', $dept->id);
    }

    private function employee(string $no, int $deptId, string $status = 'regular'): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $deptId,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'employment_status' => $status,
        ]);
    }

    private function allowance(float $amount = 2000, string $eligibility = 'all', ?array $statuses = null): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => 'Meal Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => $amount, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => $eligibility, 'eligibility_statuses' => $statuses,
        ]);
    }

    private function monthlyFor(Employee $e): float
    {
        // A fresh service: the override map is memoized per instance, as it is per request.
        return app()->make(AllowanceService::class)->monthlyFor($e->fresh());
    }

    private function url(Employee $e, BenefitProgram $p): string
    {
        return "/api/v1/hr/employees/{$e->id}/allowances/{$p->id}";
    }

    // --- the promise ------------------------------------------------------------------

    public function test_an_employee_is_paid_their_own_amount_and_nobody_else_moves(): void
    {
        $program = $this->allowance(2000);

        $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => 3000])
            ->assertOk();

        $this->assertSame(3000.0, $this->monthlyFor($this->employee));
        $this->assertSame(2000.0, $this->monthlyFor($this->colleague));
    }

    /** The property B1 broke and B6 restored by deletion: an edit must reach everyone following. */
    public function test_editing_the_programme_reaches_followers_and_leaves_the_overridden_alone(): void
    {
        $program = $this->allowance(2000);
        $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => 3000])->assertOk();

        $program->update(['amount' => 2500]);

        $this->assertSame(3000.0, $this->monthlyFor($this->employee), 'their own figure stands');
        $this->assertSame(2500.0, $this->monthlyFor($this->colleague), 'the follower moves');
    }

    public function test_clearing_hands_the_employee_back_to_the_programme(): void
    {
        $program = $this->allowance(2000);
        $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => 3000])->assertOk();

        $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => null])->assertOk();

        $this->assertSame(2000.0, $this->monthlyFor($this->employee));
        $this->assertDatabaseHas('hr_benefit_enrollments', [
            'employee_id' => $this->employee->id, 'benefit_program_id' => $program->id,
            'amount' => null, 'amount_set_by' => null,
        ]);
    }

    // --- the guards -------------------------------------------------------------------

    /** An amount is not a way onto an allowance — membership is the eligibility rule. */
    public function test_an_amount_is_refused_for_someone_the_rule_does_not_cover(): void
    {
        $program = $this->allowance(2000, 'status', ['part_time']);

        $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => 3000])
            ->assertStatus(422);

        $this->assertDatabaseMissing('hr_benefit_enrollments', [
            'employee_id' => $this->employee->id, 'benefit_program_id' => $program->id,
        ]);
        $this->assertSame(0.0, $this->monthlyFor($this->employee));
    }

    public function test_an_override_records_who_set_it_and_when(): void
    {
        $program = $this->allowance(2000);

        $rows = $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => 3000])
            ->assertOk()->json('data');

        $row = collect($rows)->firstWhere('program_id', $program->id);
        $this->assertSame(3000.0, (float) $row['amount']);
        $this->assertNotNull($row['set_by'], 'A figure with no author cannot be told from a migrated one.');
        $this->assertNotNull($row['set_at']);
    }

    public function test_the_audit_line_carries_both_figures(): void
    {
        $program = $this->allowance(2000);
        $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => 3000])->assertOk();

        $log = AuditLog::where('module', 'Benefits')->latest('id')->firstOrFail();
        $this->assertStringContainsString('3,000.00', $log->description);
        $this->assertStringContainsString('2,000.00', $log->description);
    }

    public function test_the_dialog_can_say_who_an_edit_will_reach(): void
    {
        $program = $this->allowance(2000);
        $this->actingAsHr()->putJson($this->url($this->employee, $program), ['amount' => 3000])->assertOk();

        // "14 enrolled employees will be affected" was untrue for all 14 (B6). One following, one not.
        $reach = app(BenefitProgramService::class)->reachOf($program->fresh());

        $this->assertSame(1, $reach['following']);
        $this->assertSame(1, $reach['overridden']);
    }

    public function test_an_employee_cannot_set_their_own_allowance(): void
    {
        $program = $this->allowance(2000);

        $this->actingAs($this->employee->user)
            ->putJson($this->url($this->employee, $program), ['amount' => 99000])
            ->assertForbidden();
    }

    /** The screen is built from the catalogue, so adding a fifth allowance needs no code change. */
    public function test_the_employee_allowance_list_follows_the_catalogue(): void
    {
        $this->allowance(2000);
        BenefitProgram::create([
            'name' => 'Gas Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 1500, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);

        $rows = $this->actingAsHr()->getJson("/api/v1/hr/employees/{$this->employee->id}/allowances")
            ->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['amount'], 'nobody starts with their own amount');
    }
}
