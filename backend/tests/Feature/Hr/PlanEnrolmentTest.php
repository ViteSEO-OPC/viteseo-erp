<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\BenefitDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B2 — enrolling people on a company plan.
 *
 * WHAT WAS BROKEN. The deduction engine has worked since Slice 11a and could never fire: deleting
 * "Add a Plan" removed the only screen that could put anyone on a plan or set their share, so the
 * HMO sat published, with tiers and a per-cutoff cadence, and nobody on it.
 *
 * The guards here are the ones B5 items 1 and 2 closed for the assignment path that was later
 * deleted — an ineligible employee, and a tier the plan does not have — arriving through the new
 * door rather than the old one.
 */
class PlanEnrolmentTest extends HrTestCase
{
    use RefreshDatabase;

    private const TIERS = [
        ['name' => 'Self', 'monthly_premium' => 1800, 'employee_share' => 240],
        ['name' => 'Family', 'monthly_premium' => 5600, 'employee_share' => 840],
    ];

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = $this->employee('E-001');
    }

    private function employee(string $no, string $status = 'regular'): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'employment_status' => $status,
        ]);
    }

    /** @param array<int, string>|null $statuses */
    private function plan(string $eligibility = 'all', ?array $statuses = null): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => 'MediCare Plus', 'category' => 'hmo', 'kind' => 'plan',
            'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'deduction_cadence' => 'per_cutoff', 'tiers' => self::TIERS,
            'eligibility' => $eligibility, 'eligibility_statuses' => $statuses,
            'status' => 'Active',
        ]);
    }

    private function url(BenefitProgram $p): string
    {
        return "/api/v1/hr/benefit-programs/{$p->id}/enrollments";
    }

    // --- the capability that was missing ---------------------------------------------

    public function test_an_employee_can_be_enrolled_and_the_share_defaults_from_the_tier(): void
    {
        $plan = $this->plan();

        $body = $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [['employee_id' => $this->employee->id, 'tier' => 'Family']],
        ])->assertOk()->json();

        $row = $body['data']['enrolled'][0];
        $this->assertSame('Family', $row['tier']);
        // HR typed 840 once when configuring the plan; making them type it again per person is
        // how the two drift apart.
        $this->assertSame(840.0, (float) $row['employee_share']);
        $this->assertNotNull($row['enrolled_at']);
    }

    /** THE POINT OF THE SLICE: the deduction finally reaches a payslip. */
    public function test_an_enrolled_share_is_deducted(): void
    {
        $plan = $this->plan();
        $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [['employee_id' => $this->employee->id, 'employee_share' => 500]],
        ])->assertOk();

        $result = app(BenefitDeductionService::class)->forPeriod($this->employee->fresh(), 2);

        $this->assertSame(500.0, $result['total']);
        $this->assertSame('MediCare Plus', $result['lines'][0]['name']);
    }

    // --- the guards -------------------------------------------------------------------

    public function test_an_ineligible_employee_is_rejected_by_name_not_silently_enrolled(): void
    {
        $plan = $this->plan('status', ['part_time']);

        $body = $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [['employee_id' => $this->employee->id, 'tier' => 'Self']],
        ])->assertOk()->json();

        $this->assertCount(0, $body['data']['enrolled']);
        $this->assertNotEmpty($body['rejected']);
        $this->assertStringContainsString('not eligible', $body['rejected'][0]);
    }

    /** B5 item 2 — an unknown tier used to enrol them with a NULL share (company pays it all). */
    public function test_an_unknown_tier_is_rejected(): void
    {
        $plan = $this->plan();

        $body = $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [['employee_id' => $this->employee->id, 'tier' => 'Familyy']],
        ])->assertOk()->json();

        $this->assertCount(0, $body['data']['enrolled']);
        $this->assertStringContainsString('not a tier', $body['rejected'][0]);
    }

    /** One bad row must not undo the good ones. */
    public function test_the_good_rows_are_kept_when_one_row_is_rejected(): void
    {
        $plan = $this->plan();
        $other = $this->employee('E-002');

        $body = $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [
                ['employee_id' => $this->employee->id, 'tier' => 'Self'],
                ['employee_id' => $other->id, 'tier' => 'Nope'],
            ],
        ])->assertOk()->json();

        $this->assertCount(1, $body['data']['enrolled']);
        $this->assertCount(1, $body['rejected']);
    }

    // --- dates ------------------------------------------------------------------------

    public function test_a_plan_joined_after_the_cycle_is_not_deducted_for_it(): void
    {
        $plan = $this->plan();
        $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [[
                'employee_id' => $this->employee->id,
                'employee_share' => 500,
                'enrolled_at' => '2026-08-20',
            ]],
        ])->assertOk();

        $service = app(BenefitDeductionService::class);

        // A cycle that ended before they joined charges nothing…
        $this->assertSame(0.0, $service->forPeriod($this->employee->fresh(), 2, '2026-08-01', '2026-08-15')['total']);
        // …and the one they joined in charges the full instalment.
        $this->assertSame(500.0, $service->forPeriod($this->employee->fresh(), 2, '2026-08-16', '2026-08-31')['total']);
    }

    public function test_ending_an_enrolment_stops_the_deduction_without_deleting_the_row(): void
    {
        $plan = $this->plan();
        $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [[
                'employee_id' => $this->employee->id, 'employee_share' => 500,
                'enrolled_at' => '2026-01-01', 'ended_at' => '2026-07-31',
            ]],
        ])->assertOk();

        $service = app(BenefitDeductionService::class);

        $this->assertSame(500.0, $service->forPeriod($this->employee->fresh(), 2, '2026-07-16', '2026-07-31')['total']);
        $this->assertSame(0.0, $service->forPeriod($this->employee->fresh(), 2, '2026-08-01', '2026-08-15')['total']);

        // The row survives — it is what a past payslip's deduction line refers to.
        $this->assertDatabaseHas('hr_benefit_enrollments', [
            'employee_id' => $this->employee->id, 'benefit_program_id' => $plan->id,
        ]);
    }

    // --- the warning and the shape ----------------------------------------------------

    public function test_a_plan_that_deducts_with_no_shares_is_flagged(): void
    {
        $plan = $this->plan();

        $body = $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [['employee_id' => $this->employee->id]],
        ])->assertOk()->json();

        // "Deducts every cutoff" + "nobody has a share" means deduct nothing, and looks finished.
        $this->assertTrue($body['meta']['deducts_but_no_shares']);
    }

    public function test_an_allowance_has_no_enrolments(): void
    {
        $allowance = BenefitProgram::create([
            'name' => 'Meal Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 2000, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);

        // An allowance covers people by its rule; enrolling into one would be a second, invisible
        // membership list.
        $this->actingAsHr()->putJson($this->url($allowance), [
            'enrollments' => [['employee_id' => $this->employee->id]],
        ])->assertStatus(422);
    }

    public function test_the_audit_line_names_who_was_enrolled(): void
    {
        $plan = $this->plan();
        $this->actingAsHr()->putJson($this->url($plan), [
            'enrollments' => [['employee_id' => $this->employee->id, 'tier' => 'Self']],
        ])->assertOk();

        $log = AuditLog::where('module', 'Benefits')->latest('id')->firstOrFail();
        $this->assertStringContainsString('MediCare Plus', $log->description);
        $this->assertStringContainsString((string) $this->employee->id, $log->description);
    }

    public function test_an_employee_cannot_enrol_themselves(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->employee->user)->putJson($this->url($plan), [
            'enrollments' => [['employee_id' => $this->employee->id, 'tier' => 'Family']],
        ])->assertForbidden();
    }
}
