<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AllowanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B12 — the employee's own benefits page says what each benefit is worth.
 *
 * IT USED TO SAY NOTHING. The resource carried a name, a category and a status; the three fields
 * meant to hold content are empty on every programme. So the only benefits screen an employee has
 * told them less than their payslip did, and it could not be fixed in the frontend because the
 * money was never sent.
 */
class MyBenefitsTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'employment_status' => 'regular',
        ]);
    }

    private function allowance(string $name, float $amount, string $frequency = 'monthly'): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => $name, 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => $amount, 'frequency' => $frequency, 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function mine(): array
    {
        return $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/me/benefit-programs')
            ->assertOk()
            ->json('data');
    }

    public function test_an_allowance_card_carries_its_monthly_amount(): void
    {
        $this->allowance('Meal Allowance', 2000);

        $row = $this->mine()[0];

        $this->assertSame('Meal Allowance', $row['name']);
        $this->assertSame(2000.0, (float) $row['monthly_amount']);
        $this->assertSame('de_minimis', $row['tax_treatment']);
    }

    /** The page total has to be the same arithmetic as the payslip, not a second implementation. */
    public function test_the_amounts_match_what_payroll_would_pay(): void
    {
        $this->allowance('Meal Allowance', 2000);
        $this->allowance('Annual Bonus Allowance', 12000, 'annual'); // ⇒ ₱1,000/month

        $total = collect($this->mine())->sum(fn ($r) => (float) ($r['monthly_amount'] ?? 0));

        $this->assertSame(3000.0, $total);
        $this->assertSame(app(AllowanceService::class)->monthlyFor($this->employee->fresh()), $total);
    }

    /** B14 + the 2026-08-03 decision: their own figure, and no hint of the company standard. */
    public function test_an_employee_with_their_own_amount_sees_it_without_a_comparison(): void
    {
        $program = $this->allowance('Meal Allowance', 2000);

        $this->actingAsHr()->putJson(
            "/api/v1/hr/employees/{$this->employee->id}/allowances/{$program->id}",
            ['amount' => 3000],
        )->assertOk();

        $row = $this->mine()[0];

        $this->assertSame(3000.0, (float) $row['monthly_amount']);
        // Nobody should learn from a self-service page that they are paid above or below the norm.
        $this->assertArrayNotHasKey('program_amount', $row);
        $this->assertArrayNotHasKey('set_by', $row);
    }

    public function test_a_company_plan_shows_the_employees_own_share_and_tier(): void
    {
        $plan = BenefitProgram::create([
            'name' => 'MediCare Plus', 'category' => 'hmo', 'kind' => 'company',
            'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'deduction_cadence' => 'per_cutoff', 'status' => 'Active',
        ]);
        $this->employee->benefitPrograms()->attach($plan->id, [
            'employee_share' => 500, 'tier' => 'Family',
        ]);

        $row = collect($this->mine())->firstWhere('name', 'MediCare Plus');

        $this->assertSame(500.0, (float) $row['employee_share']);
        $this->assertSame('Family', $row['tier']);
        $this->assertNull($row['monthly_amount'], 'A plan is not money added to pay.');
    }

    /** Nothing cross-employee has ever been on this page, and nothing here changes that. */
    public function test_the_page_carries_no_other_employees_data(): void
    {
        $this->allowance('Meal Allowance', 2000);

        $row = $this->mine()[0];

        foreach (['covered_count', 'reach', 'employee_ids'] as $leak) {
            $this->assertArrayNotHasKey($leak, $row);
        }
    }
}
