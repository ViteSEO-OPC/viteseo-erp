<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B15 — a loan is a product HR defines, and a deduction the employee has agreed to.
 *
 * THE AUTHORIZATION IS THE POINT. Labor Code Art. 113 permits deducting a debt owed to the
 * employer only with the employee's explicit written consent, so both routes into a loan — HR
 * issuing one, or the employee asking — end at the same gate. A loan no longer starts `active`,
 * and `collectForPeriod()` reads `active` only, so an unauthorized loan cannot be collected by
 * construction rather than by a check somebody has to remember.
 */
class LoanAuthorizationTest extends HrTestCase
{
    use RefreshDatabase;

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
    private function policy(
        ?float $max = 50000,
        ?int $term = 12,
        string $eligibility = 'all',
        ?array $statuses = null,
    ): BenefitProgram {
        return BenefitProgram::create([
            'name' => 'Company Emergency Loan', 'category' => 'general', 'kind' => 'loan_policy',
            'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'loan_max_principal' => $max, 'loan_term_periods' => $term,
            'eligibility' => $eligibility, 'eligibility_statuses' => $statuses,
        ]);
    }

    /** @return array<string, mixed> */
    private function loanBody(BenefitProgram $policy, float $principal = 12000, float $perPeriod = 1000): array
    {
        return [
            'benefit_program_id' => $policy->id,
            'principal' => $principal,
            'per_period_amount' => $perPeriod,
            'start_date' => '2026-01-01',
        ];
    }

    // --- the gate ---------------------------------------------------------------------

    /** THE RULE: HR issuing a loan does not start deducting from anybody's pay. */
    public function test_an_hr_issued_loan_waits_for_the_employees_authorization(): void
    {
        $policy = $this->policy();

        $body = $this->actingAsHr()
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/loans", $this->loanBody($policy))
            ->assertCreated()
            ->json('data');

        $this->assertSame('pending_authorization', $body['status']);
        $this->assertTrue($body['needs_authorization']);
        $this->assertFalse($body['is_collectible']);
        $this->assertNull($body['authorized_at']);
    }

    public function test_an_unauthorized_loan_is_not_collected_by_payroll(): void
    {
        $policy = $this->policy();
        $this->actingAsHr()
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/loans", $this->loanBody($policy))
            ->assertCreated();

        $run = PayrollRun::create([
            'cycle_start' => '2026-02-01', 'cycle_end' => '2026-02-15',
            'frequency' => 'semi_monthly', 'status' => 'completed',
        ]);

        $collected = app(LoanService::class)
            ->collectForPeriod($this->employee->fresh(), $run, '2026-02-15', 50000);

        $this->assertSame(0.0, $collected['total'], 'Deducting without consent is unlawful.');
        $this->assertSame([], $collected['lines']);
    }

    public function test_the_employee_authorizes_and_then_it_is_collected(): void
    {
        $policy = $this->policy();
        $loan = app(LoanService::class)->create(
            $this->employee,
            $this->loanBody($policy),
            User::factory()->create(),
        );

        $this->actingAs($this->employee->user)
            ->postJson("/api/v1/hr/me/loans/{$loan->id}/authorize")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_collectible', true);

        $run = PayrollRun::create([
            'cycle_start' => '2026-02-01', 'cycle_end' => '2026-02-15',
            'frequency' => 'semi_monthly', 'status' => 'completed',
        ]);

        $collected = app(LoanService::class)
            ->collectForPeriod($this->employee->fresh(), $run, '2026-02-15', 50000);

        $this->assertSame(1000.0, $collected['total']);
    }

    /** Consent has to be the borrower's own — a record HR could create would be worth nothing. */
    public function test_nobody_else_can_authorize_it_for_them(): void
    {
        $policy = $this->policy();
        $loan = app(LoanService::class)->create(
            $this->employee,
            $this->loanBody($policy),
            User::factory()->create(),
        );

        $this->actingAsHr()
            ->postJson("/api/v1/hr/me/loans/{$loan->id}/authorize")
            ->assertStatus(422);

        $this->assertSame('pending_authorization', $loan->fresh()->status);
    }

    // --- the two routes in ------------------------------------------------------------

    public function test_an_employee_can_request_and_hr_approves_it_into_pending_authorization(): void
    {
        $policy = $this->policy();

        $requested = $this->actingAs($this->employee->user)
            ->postJson('/api/v1/hr/me/loans', $this->loanBody($policy))
            ->assertCreated()
            ->json('data');

        $this->assertSame('requested', $requested['status']);

        $this->actingAsHr()
            ->postJson("/api/v1/hr/loans/{$requested['id']}/approve")
            ->assertOk()
            // Approval is the company's decision, not the employee's consent — still not active.
            ->assertJsonPath('data.status', 'pending_authorization');
    }

    public function test_hr_can_reject_a_request(): void
    {
        $policy = $this->policy();
        $requested = $this->actingAs($this->employee->user)
            ->postJson('/api/v1/hr/me/loans', $this->loanBody($policy))
            ->assertCreated()->json('data');

        $this->actingAsHr()
            ->postJson("/api/v1/hr/loans/{$requested['id']}/reject", ['reason' => 'Too soon after the last one'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    // --- the policy is a product, not decoration --------------------------------------

    public function test_a_loan_over_the_policy_maximum_is_refused(): void
    {
        $policy = $this->policy(max: 10000);

        $this->actingAsHr()
            ->postJson(
                "/api/v1/hr/employees/{$this->employee->id}/loans",
                $this->loanBody($policy, principal: 20000, perPeriod: 2000),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('principal');
    }

    public function test_an_instalment_that_runs_past_the_term_is_refused(): void
    {
        $policy = $this->policy(max: 50000, term: 6);

        // ₱12,000 at ₱1,000 a period is 12 periods — twice the term this policy allows.
        $this->actingAsHr()
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/loans", $this->loanBody($policy))
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_period_amount');
    }

    /** Who may borrow is the policy's eligibility rule — the same one allowances use. */
    public function test_an_employee_the_policy_does_not_cover_cannot_borrow(): void
    {
        $policy = $this->policy(eligibility: 'status', statuses: ['part_time']);

        $this->actingAs($this->employee->user)
            ->postJson('/api/v1/hr/me/loans', $this->loanBody($policy))
            ->assertStatus(422)
            ->assertJsonValidationErrors('benefit_program_id');
    }

    public function test_a_programme_that_is_not_a_loan_policy_is_refused(): void
    {
        $allowance = BenefitProgram::create([
            'name' => 'Meal Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 2000, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);

        $this->actingAs($this->employee->user)
            ->postJson('/api/v1/hr/me/loans', [
                'benefit_program_id' => $allowance->id,
                'principal' => 5000, 'per_period_amount' => 500, 'start_date' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('benefit_program_id');
    }

    // --- the employee's own view ------------------------------------------------------

    public function test_an_employee_sees_their_own_loans_and_not_anybody_elses(): void
    {
        $policy = $this->policy();
        $colleague = $this->employee('E-002');

        app(LoanService::class)->create($this->employee, $this->loanBody($policy), User::factory()->create());
        app(LoanService::class)->create($colleague, $this->loanBody($policy), User::factory()->create());

        $mine = $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/me/loans')->assertOk()->json('data');

        $this->assertCount(1, $mine);
        $this->assertSame($this->employee->id, $mine[0]['employee_id']);
    }

    public function test_the_authorization_is_audited_in_the_employees_own_words(): void
    {
        $policy = $this->policy();
        $loan = app(LoanService::class)->create($this->employee, $this->loanBody($policy), User::factory()->create());

        $this->actingAs($this->employee->user)
            ->postJson("/api/v1/hr/me/loans/{$loan->id}/authorize")->assertOk();

        $log = AuditLog::where('module', 'Loans')->latest('id')->firstOrFail();

        $this->assertStringContainsString('authorized', $log->description);
        $this->assertStringContainsString('deducted from their pay', $log->description);
    }

    /** A shape guard: nothing may create a loan that is collectible on the spot. */
    public function test_no_route_creates_an_immediately_collectible_loan(): void
    {
        $policy = $this->policy();

        app(LoanService::class)->create($this->employee, $this->loanBody($policy), User::factory()->create());
        app(LoanService::class)->request($this->employee, $this->loanBody($policy));

        $this->assertSame(
            0,
            EmployeeLoan::where('status', 'active')->count(),
            'A loan must never start collectible — consent comes first.',
        );
    }
}
