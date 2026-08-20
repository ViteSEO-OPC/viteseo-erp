<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** (payroll audit, §3) — the wage-deduction consent gate could be walked around. */
class LoanConsentGateTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
        $this->hr = $this->hrUser();
    }

    private function pendingLoan(): EmployeeLoan
    {
        return EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'pending_authorization',
        ]);
    }

    /** The full payload the edit form submits — the realistic path, not a crafted request. */
    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'loan',
            'principal' => 12000,
            'per_period_amount' => 1000,
            'start_date' => '2026-01-01',
        ], $overrides);
    }

    // --- (a) editing must not grant consent ---------------------------------------------

    public function test_editing_a_loan_cannot_activate_it_without_the_employees_authorization(): void
    {
        $loan = $this->pendingLoan();

        try {
            app(LoanService::class)->update($loan, $this->editPayload(['status' => 'active']), $this->hr);
        } catch (\Illuminate\Validation\ValidationException) {
            // Refusing outright is an acceptable implementation of the same rule.
        }

        $fresh = $loan->fresh();
        $this->assertNotSame('active', $fresh->status, 'HR activated a loan the employee never authorized');
        $this->assertNull($fresh->authorized_at, 'a loan is active with no consent record');
    }

    /** Editing the amounts is legitimate and must keep working — this is not a lockdown. */
    public function test_editing_a_loans_amounts_still_works(): void
    {
        $loan = $this->pendingLoan();

        app(LoanService::class)->update($loan, $this->editPayload(['per_period_amount' => 750]), $this->hr);

        $this->assertSame(750.0, (float) $loan->fresh()->per_period_amount);
        $this->assertSame('pending_authorization', $loan->fresh()->status);
    }

    // --- (b) reopening must not resurrect consent that was never given -------------------

    public function test_reopening_a_never_authorized_loan_returns_it_to_pending_not_active(): void
    {
        $loan = $this->pendingLoan();
        app(LoanService::class)->cancel($loan, $this->hr, 'issued in error');

        app(LoanService::class)->reopen($loan->fresh(), 'reinstated');

        $fresh = $loan->fresh();
        $this->assertSame(
            'pending_authorization',
            $fresh->status,
            'a loan the employee never authorized was reopened straight into collection',
        );
        $this->assertNull($fresh->authorized_at);
    }

    /** A loan the employee HAD authorised keeps its consent, so reopening resumes collection. */
    public function test_reopening_a_previously_authorized_loan_resumes_collection(): void
    {
        $loan = $this->pendingLoan();
        app(LoanService::class)->authorize($loan, $this->employee->user);
        app(LoanService::class)->cancel($loan->fresh(), $this->hr, 'paused');

        app(LoanService::class)->reopen($loan->fresh(), 'resumed');

        $this->assertSame('active', $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->authorized_at);
    }

    // --- the invariant, stated once ------------------------------------------------------

    /** Only the employee can grant consent — unchanged behaviour, asserted so it stays true. */
    public function test_hr_cannot_authorize_on_the_employees_behalf(): void
    {
        $loan = $this->pendingLoan();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LoanService::class)->authorize($loan, $this->hr);
    }

    // ---: the product ceiling holds for the life of the loan --------------------------

    /**
     * `create()` and `request()` both checked the policy; `update()` did not. So a loan issued
     * validly under a ₱50,000 product could be edited past it the next day — the ceiling enforced
     * once and never again, the same shape of hole as the status bypass above.
     */
    public function test_editing_a_loan_cannot_exceed_its_products_maximum(): void
    {
        $policy = \App\Modules\Hr\Models\BenefitProgram::create([
            'name' => 'Staff loan', 'category' => 'loan', 'kind' => 'loan_policy',
            'status' => 'active', 'amount' => 0,
            'loan_max_principal' => 50000, 'loan_term_periods' => 24,
        ]);

        $loan = EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'benefit_program_id' => $policy->id,
            'type' => 'loan', 'principal' => 50000, 'per_period_amount' => 5000,
            'start_date' => '2026-01-01', 'status' => 'pending_authorization',
        ]);

        try {
            app(LoanService::class)->update($loan, [
                'principal' => 200000, 'per_period_amount' => 5000, 'start_date' => '2026-01-01',
            ], $this->hr);
            $this->fail('a loan was edited past its product maximum');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('maximum', implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame(50000.0, (float) $loan->fresh()->principal);
    }

    /** An edit that still fits the product must go through — this is a ceiling, not a lock. */
    public function test_editing_within_the_products_maximum_still_works(): void
    {
        $policy = \App\Modules\Hr\Models\BenefitProgram::create([
            'name' => 'Staff loan', 'category' => 'loan', 'kind' => 'loan_policy',
            'status' => 'active', 'amount' => 0,
            'loan_max_principal' => 50000, 'loan_term_periods' => 24,
        ]);

        $loan = EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'benefit_program_id' => $policy->id,
            'type' => 'loan', 'principal' => 20000, 'per_period_amount' => 2000,
            'start_date' => '2026-01-01', 'status' => 'pending_authorization',
        ]);

        app(LoanService::class)->update($loan, [
            'principal' => 30000, 'per_period_amount' => 2000, 'start_date' => '2026-01-01',
        ], $this->hr);

        $this->assertSame(30000.0, (float) $loan->fresh()->principal);
    }
}
