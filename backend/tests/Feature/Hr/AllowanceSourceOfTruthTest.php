<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AllowanceService;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B1 — Company → Benefits is the source of truth for allowances.
 *
 * Allowances lived in two places that disagreed: the Benefits page showed ₱77,000/month across
 * the company, payroll paid ₱42,000 from three hard-coded `hr_employees` columns. Editing the page
 * changed nobody's payslip, and Gas Allowance had no column at all so it could never be paid.
 *
 * The behaviour that matters most here is the PRECEDENCE: a null pivot amount means "follow the
 * programme", a non-null one is a deliberate per-employee override. Get that backwards and either
 * editing a programme reaches nobody, or every carefully-set individual amount is wiped.
 */
class AllowanceSourceOfTruthTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            // Deliberately set: these columns must no longer reach a payslip.
            'food_allowance' => 9999, 'transportation_allowance' => 9999, 'load_allowance' => 9999,
        ]);
    }

    private function programme(string $name, float $amount, string $eligibility = 'all'): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => $name, 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => $amount, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => $eligibility, 'is_active' => true,
        ]);
    }

    private function monthly(): float
    {
        return app(AllowanceService::class)->monthlyFor($this->employee->fresh());
    }

    // --- the source of truth --------------------------------------------------------

    public function test_the_old_employee_columns_no_longer_pay(): void
    {
        $this->assertSame(0.0, $this->monthly(), 'not enrolled in anything ⇒ no allowance');
    }

    public function test_an_enrolled_programme_pays_its_amount(): void
    {
        $p = $this->programme('Meal Allowance', 2000);
        $this->employee->benefitPrograms()->attach($p->id);

        $this->assertSame(2000.0, $this->monthly());
    }

    /** THE POINT OF THE SLICE: editing the programme reaches everyone enrolled. */
    public function test_editing_the_programme_moves_everyone_enrolled(): void
    {
        $p = $this->programme('Meal Allowance', 2000);
        $this->employee->benefitPrograms()->attach($p->id);

        $p->update(['amount' => 2500]);

        $this->assertSame(2500.0, $this->monthly());
    }

    /** A pinned amount is a deliberate override and does NOT follow the programme. */
    public function test_a_per_employee_override_wins(): void
    {
        $p = $this->programme('Meal Allowance', 2000);
        $this->employee->benefitPrograms()->attach($p->id, ['amount' => 3000]);

        $this->assertSame(3000.0, $this->monthly());

        $p->update(['amount' => 2500]);
        $this->assertSame(3000.0, $this->monthly(), 'the override is not swept along');
    }

    public function test_clearing_an_override_hands_them_back_to_the_programme(): void
    {
        $p = $this->programme('Meal Allowance', 2000);
        $this->employee->benefitPrograms()->attach($p->id, ['amount' => 3000]);

        app(AllowanceService::class)->clearOverride($this->employee, $p->id);

        $this->assertSame(2000.0, $this->monthly());
    }

    /** A ₱0 override is a real decision ("enrolled but not paid"), not "unset". */
    public function test_a_zero_override_is_respected(): void
    {
        $p = $this->programme('Gas Allowance', 1500);
        $this->employee->benefitPrograms()->attach($p->id, ['amount' => 0]);

        $this->assertSame(0.0, $this->monthly());
    }

    public function test_an_inactive_programme_pays_nothing(): void
    {
        $p = $this->programme('Meal Allowance', 2000);
        $p->update(['is_active' => false]);
        $this->employee->benefitPrograms()->attach($p->id);

        $this->assertSame(0.0, $this->monthly());
    }

    public function test_several_programmes_add_up(): void
    {
        foreach ([['Meal', 2000], ['Transport', 1500], ['Gas', 1500], ['Load', 500]] as [$n, $a]) {
            $this->employee->benefitPrograms()->attach($this->programme($n, $a)->id);
        }

        $this->assertSame(5500.0, $this->monthly(), 'Gas pays now — it never could before');
    }

    // --- payroll ---------------------------------------------------------------------

    public function test_the_allowance_reaches_the_payslip(): void
    {
        foreach ([['Meal', 2000], ['Transport', 1500]] as [$n, $a]) {
            $this->employee->benefitPrograms()->attach($this->programme($n, $a)->id);
        }

        $slip = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create())
            ->payslips()->firstOrFail();

        // ₱3,500/month × 12 ÷ 24 periods = ₱1,750 this period.
        $this->assertSame(1750.0, (float) $slip->allowances);
    }

    // --- reconciliation --------------------------------------------------------------

    public function test_reconciliation_lists_only_real_differences(): void
    {
        $matching = $this->programme('Meal Allowance', 2000);
        $drifting = $this->programme('Transport Allowance', 1500);
        $this->employee->benefitPrograms()->attach($matching->id, ['amount' => 2000]); // same
        $this->employee->benefitPrograms()->attach($drifting->id, ['amount' => 0]);    // differs

        $rows = app(AllowanceService::class)->reconciliation();

        $this->assertCount(1, $rows);
        $this->assertSame(-1500.0, $rows[0]['delta']);
        $this->assertCount(1, $rows[0]['lines'], 'the matching line is not noise');
    }

    public function test_the_endpoints_are_reachable(): void
    {
        $p = $this->programme('Meal Allowance', 2000);
        $this->employee->benefitPrograms()->attach($p->id, ['amount' => 3000]);

        $this->actingAsHr()
            ->getJson('/api/v1/hr/benefit-programs/allowance-reconciliation')
            ->assertOk()
            ->assertJsonPath('data.0.delta', 1000);

        $this->actingAsHr()
            ->deleteJson("/api/v1/hr/employees/{$this->employee->id}/allowance-overrides/{$p->id}")
            ->assertOk();

        $this->assertSame(2000.0, $this->monthly());
    }

    // --- new hires -------------------------------------------------------------------

    /** Without this a new hire would silently earn no allowances at all. */
    public function test_a_new_hire_is_enrolled_in_the_open_allowances(): void
    {
        $open = $this->programme('Meal Allowance', 2000);
        $restricted = $this->programme('Managers Only', 5000, eligibility: 'regular');

        // EmployeeService::create() assigns the hr_employee role to the new user.
        $this->hrUser();

        $hire = app(\App\Modules\Hr\Services\EmployeeService::class)->create([
            'name' => 'New Hire', 'email' => 'new.hire@viteseo.ph',
            'department_id' => Department::first()->id, 'job_title' => 'Staff',
            'hire_date' => '2026-03-01', 'base_salary' => 25000,
        ]);

        $enrolled = $hire->benefitPrograms()->pluck('hr_benefit_programs.id')->all();

        $this->assertContains($open->id, $enrolled);
        $this->assertNotContains($restricted->id, $enrolled, 'restricted programmes stay HR\'s call');
    }
}
