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
 * The behaviour that matters most here is that there is ONE amount per allowance: the programme's.
 * Editing it reaches everyone enrolled. B1 shipped with a per-employee override on the enrolment
 * and a backfill that pinned all 56 of them, which meant an edit reached nobody at all; the
 * override was dropped outright on 2026-07-29 (B6) — the company does not want per-employee
 * amounts, so the enrolment carries no figure to disagree with.
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
            'eligibility' => $eligibility,
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

    public function test_a_deleted_programme_pays_nothing(): void
    {
        // B13 item 4 — deleting IS the off switch now; `is_active` was a second, unreachable one.
        $p = $this->programme('Meal Allowance', 2000);
        $this->employee->benefitPrograms()->attach($p->id);
        $p->delete();

        $this->assertSame(0.0, $this->monthly());
    }

    public function test_several_programmes_add_up(): void
    {
        foreach ([['Meal', 2000], ['Transport', 1500], ['Gas', 1500], ['Load', 500]] as [$n, $a]) {
            $this->employee->benefitPrograms()->attach($this->programme($n, $a)->id);
        }

        $this->assertSame(5500.0, $this->monthly(), 'Gas pays now — it never could before');
    }

    /**
     * The enrolment carries NO amount at all (B6, 2026-07-29). Guarding the shape rather than the
     * behaviour: a re-added pivot column would quietly restore the bug where editing an allowance
     * reached nobody, and every other test here would still pass.
     */
    public function test_an_enrolment_carries_no_per_employee_amount(): void
    {
        $p = $this->programme('Meal Allowance', 2000);
        $this->employee->benefitPrograms()->attach($p->id);

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('hr_benefit_enrollments');

        $this->assertNotContains('amount', $columns);
        $this->assertSame(2000.0, $this->monthly());
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

    // --- deleting an allowance --------------------------------------------------------

    /**
     * Deleting stops future pay but must not rewrite what was already paid.
     *
     * `allowanceBreakdown()` snapshots the lines onto the payslip when the run happens, so the
     * document keeps naming an allowance that no longer exists — which is what a payslip is for.
     */
    public function test_deleting_an_allowance_stops_future_pay_but_keeps_past_payslips(): void
    {
        $meal = $this->programme('Meal Allowance', 2000);
        $this->programme('Transport Allowance', 1500);

        $run = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $before = $run->payslips()->firstOrFail();

        // ₱3,500/month × 12 ÷ 24 = ₱1,750.
        $this->assertSame(1750.0, (float) $before->allowances);
        $this->assertContains('Meal Allowance', array_column($before->allowances_breakdown, 'name'));

        app(\App\Modules\Hr\Services\BenefitProgramService::class)->delete($meal);

        // The old payslip is untouched, and still names the deleted allowance.
        $before->refresh();
        $this->assertSame(1750.0, (float) $before->allowances);
        $this->assertContains('Meal Allowance', array_column($before->allowances_breakdown, 'name'));

        // The next run pays only what is left.
        $this->assertSame(1500.0, $this->monthly(), 'Transport only');
    }

    public function test_a_deleted_allowance_disappears_from_the_catalogue(): void
    {
        $p = $this->programme('Meal Allowance', 2000);

        $this->actingAsHr()->deleteJson("/api/v1/hr/benefit-programs/{$p->id}")->assertNoContent();

        $this->actingAsHr()
            ->getJson('/api/v1/hr/benefit-programs')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0.0, $this->monthly());
    }

    // --- new hires -------------------------------------------------------------------

    /**
     * A new hire picks up everything their rule covers, with nothing to remember.
     *
     * This used to enrol them explicitly in the open programmes, which meant a hire made before a
     * new allowance existed never got it. Eligibility is a rule now, so the answer is computed
     * rather than stored — and "Managers Only" correctly passes them by.
     */
    public function test_a_new_hire_gets_the_allowances_their_rule_covers(): void
    {
        $open = $this->programme('Meal Allowance', 2000);
        $restricted = $this->programme('Managers Only', 5000, eligibility: 'management');

        // EmployeeService::create() assigns the hr_employee role to the new user.
        $this->hrUser();

        $hire = app(\App\Modules\Hr\Services\EmployeeService::class)->create([
            'name' => 'New Hire', 'email' => 'new.hire@viteseo.ph',
            'department_id' => Department::first()->id, 'job_title' => 'Staff',
            'hire_date' => '2026-03-01', 'base_salary' => 25000,
        ]);

        $covered = app(\App\Modules\Hr\Services\BenefitProgramService::class)
            ->allowancesFor($hire)->pluck('id')->all();

        $this->assertContains($open->id, $covered);
        $this->assertNotContains($restricted->id, $covered, 'a management-only allowance passes them by');
        $this->assertSame(2000.0, app(AllowanceService::class)->monthlyFor($hire), 'and it pays');
    }
}
