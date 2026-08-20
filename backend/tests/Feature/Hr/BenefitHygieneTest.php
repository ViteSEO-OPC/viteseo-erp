<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use App\Modules\Hr\Services\BenefitProgramService;
use App\Modules\Hr\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/** The state and counting rules the page depends on. */
class BenefitHygieneTest extends HrTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    /** @param array<string, mixed> $attributes */
    private function employee(string $no, array $attributes = []): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'employment_status' => 'regular',
        ] + $attributes);
    }

    private function allowance(string $name = 'Meal Allowance'): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => $name, 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 2000, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);
    }

    // --- item 4: one off switch ------------------------------------------------------

    /** A shape test, like B6's: re-adding the column should fail here rather than quietly return. */
    public function test_a_programme_has_no_is_active_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('hr_benefit_programs', 'is_active'),
            'A programme is switched off by deleting it. A second off switch was unreachable from the UI.',
        );
    }

    public function test_an_allowance_is_saved_as_active_rather_than_enrolled(): void
    {
        $program = app(BenefitProgramService::class)->create([
            'name' => 'Field Allowance', 'kind' => 'allowance', 'category' => 'allowance',
            'amount' => 800, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);

        // Two words for one thing used to appear on the same page: seeded allowances said
        // "Active", ones created through the form said "Enrolled".
        $this->assertSame('Active', $program->status);
    }

    public function test_the_employee_card_derives_an_allowance_badge_and_keeps_a_plan_status(): void
    {
        $employee = $this->employee('E-001');
        $allowance = $this->allowance();
        $allowance->update(['status' => 'Whatever HR typed']);

        $plan = BenefitProgram::create([
            'name' => 'MediCare', 'category' => 'hmo', 'kind' => 'company',
            'frequency' => 'monthly', 'tax_treatment' => 'de_minimis', 'status' => 'Coming soon',
        ]);
        $employee->benefitPrograms()->attach($plan->id);

        $this->actingAs($employee->user)->getJson('/api/v1/hr/me/benefit-programs')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'Active')          // the allowance, derived
            ->assertJsonPath('data.1.status', 'Coming soon');    // the plan, as authored
    }

    // --- item 6: who a rule covers ---------------------------------------------------

    public function test_a_separated_employee_is_not_counted_as_covered(): void
    {
        $this->employee('E-001');
        $this->employee('E-002', ['separation_type' => 'resigned', 'separation_date' => '2026-06-30']);

        // Payroll stopped paying them on separation; the card said "everyone (2 people)".
        $this->assertSame(1, app(BenefitProgramService::class)->eligibleCount($this->allowance()));
    }

    public function test_a_separated_employee_is_not_in_the_eligible_ids(): void
    {
        $stays = $this->employee('E-001');
        $leaves = $this->employee('E-002', ['separation_type' => 'resigned', 'separation_date' => '2026-06-30']);

        $ids = app(BenefitProgramService::class)->eligibleEmployeeIds($this->allowance());

        $this->assertContains($stays->id, $ids);
        $this->assertNotContains($leaves->id, $ids);
    }

    /** One roster read for the whole catalogue, not one count per card. */
    public function test_counting_a_catalogue_does_not_query_once_per_programme(): void
    {
        $this->employee('E-001');
        foreach (['Meal', 'Transport', 'Gas', 'Load'] as $name) {
            $this->allowance($name);
        }

        $service = app(BenefitProgramService::class);
        $programmes = BenefitProgram::all();

        \DB::enableQueryLog();
        foreach ($programmes as $programme) {
            $service->eligibleCount($programme);
        }
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries, 'The employee roster is read once and the rule evaluated in PHP.');
    }

    // --- item 7: coverage counts live people -----------------------------------------

    public function test_benefits_coverage_ignores_a_deleted_employees_checklist_rows(): void
    {
        $live = $this->employee('E-001');
        $gone = $this->employee('E-002');

        foreach ([$live, $gone] as $employee) {
            foreach (['sss', 'philhealth', 'pagibig'] as $type) {
                EmployeeBenefit::create([
                    'employee_id' => $employee->id, 'contribution_type' => $type, 'enrolled' => true,
                ]);
            }
        }

        $gone->delete();

        // The row survives its employee on purpose — a restore must bring the enrolment back.
        $this->assertSame(3, EmployeeBenefit::where('employee_id', $gone->id)->count());

        // …but 3 rows against 1 live employee used to read 300%. On the live database it read 150%.
        $report = app(ReportService::class)->get('compensation', []);
        $coverage = collect($report['summary'])->firstWhere('label', 'Benefits coverage');

        $this->assertSame('100%', $coverage['value']);
    }
}
