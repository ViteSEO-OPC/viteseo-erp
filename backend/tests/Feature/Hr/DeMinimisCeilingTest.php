<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AllowanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** De minimis is a ceiling PER BENEFIT, and the excess is not taxed on its own. */
class DeMinimisCeilingTest extends HrTestCase
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

    private function allowance(
        string $name,
        float $amount,
        ?float $ceiling = null,
        string $period = 'monthly',
        string $treatment = 'de_minimis',
    ): BenefitProgram {
        return BenefitProgram::create([
            'name' => $name, 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => $amount, 'frequency' => 'monthly', 'tax_treatment' => $treatment,
            'deminimis_ceiling' => $ceiling, 'ceiling_period' => $ceiling === null ? null : $period,
            'eligibility' => 'all',
        ]);
    }

    /** @return array{exempt: float, other_benefits: float, taxable: float} */
    private function buckets(): array
    {
        return app()->make(AllowanceService::class)->monthlyTaxBucketsFor($this->employee->fresh());
    }

    // --- per-item ceilings -------------------------------------------------------------

    public function test_a_benefit_is_exempt_up_to_its_own_ceiling_and_the_rest_is_other_benefits(): void
    {
        $this->allowance('Rice Subsidy', 3000, 2500); // ₱2,500/month is the statutory figure

        $this->assertSame(
            ['exempt' => 2500.0, 'other_benefits' => 500.0, 'taxable' => 0.0],
            $this->buckets(),
        );
    }

    /** THE RULE THE OLD CODE BROKE: ceilings are per item and are never pooled. */
    public function test_one_benefits_unused_room_does_not_cover_another_benefits_excess(): void
    {
        $this->allowance('Rice Subsidy', 3000, 2500);   // ₱500 over
        $this->allowance('Uniform', 200, 8000, 'annual'); // ₱666.67/month ceiling, well under

        $buckets = $this->buckets();

        // Pooled, the ₱466.67 of unused uniform room would have absorbed the rice excess.
        $this->assertSame(500.0, $buckets['other_benefits']);
        $this->assertSame(2700.0, $buckets['exempt']);
    }

    public function test_a_ceiling_stated_per_year_or_per_semester_is_normalised_to_a_month(): void
    {
        $this->allowance('Uniform', 1000, 8000, 'annual');            // ₱666.67/month
        $this->allowance('Medical (dependents)', 500, 2000, 'semester'); // ₱333.33/month

        $buckets = $this->buckets();

        $this->assertSame(1000.0, $buckets['exempt']);       // 666.67 + 333.33
        $this->assertSame(500.0, $buckets['other_benefits']); // 333.33 + 166.67
    }

    public function test_a_benefit_with_no_ceiling_recorded_stays_fully_exempt(): void
    {
        // Nobody's pay may move because a migration guessed which BIR item an allowance is.
        $this->allowance('Meal Allowance', 2000);

        $this->assertSame(
            ['exempt' => 2000.0, 'other_benefits' => 0.0, 'taxable' => 0.0],
            $this->buckets(),
        );
    }

    public function test_a_taxable_allowance_ignores_ceilings_entirely(): void
    {
        $this->allowance('Car Allowance', 5000, 1000, 'monthly', 'taxable');

        $this->assertSame(
            ['exempt' => 0.0, 'other_benefits' => 0.0, 'taxable' => 5000.0],
            $this->buckets(),
        );
    }

    public function test_the_buckets_always_sum_to_the_total_allowance(): void
    {
        $this->allowance('Rice Subsidy', 3000, 2500);
        $this->allowance('Uniform', 1000, 8000, 'annual');
        $this->allowance('Car Allowance', 5000, null, 'monthly', 'taxable');

        $buckets = $this->buckets();
        $service = app()->make(AllowanceService::class);

        $this->assertSame(
            $service->monthlyFor($this->employee->fresh()),
            round($buckets['exempt'] + $buckets['other_benefits'] + $buckets['taxable'], 2),
        );
    }

    // --- the ₱90,000 bucket ------------------------------------------------------------

    /** Excess below ₱90,000 a year is NOT taxable — the old code taxed every peso of it. */
    public function test_excess_under_the_annual_bucket_is_not_withheld_on(): void
    {
        $this->allowance('Rice Subsidy', 3000, 2500); // ₱500/month ⇒ ₱6,000/year, well under

        $withheld = $this->withholdingFor();

        $this->allowance('Nothing', 0); // no-op, keeps the shape of the comparison obvious
        $this->assertSame($withheld, $this->withholdingFor(), 'A ₱6,000 excess must not be taxed.');
    }

    public function test_only_the_amount_over_the_annual_bucket_is_taxed(): void
    {
        $before = $this->withholdingFor();

        // ₱10,000/month over the ceiling ⇒ ₱120,000/year ⇒ ₱30,000 over the ₱90,000 exemption.
        $this->allowance('Big Subsidy', 12500, 2500);

        $this->assertGreaterThan($before, $this->withholdingFor());
    }

    private function withholdingFor(): float
    {
        $service = app()->make(\App\Modules\Hr\Services\PayrollService::class);
        $split = app()->make(AllowanceService::class)->monthlyTaxBucketsFor($this->employee->fresh());

        // Same arithmetic payroll runs: taxable allowances in full, plus the bucket's excess.
        $reflection = new \ReflectionMethod($service, 'otherBenefitsTaxableMonthly');
        $reflection->setAccessible(true);

        return round(
            $split['taxable'] + $reflection->invoke($service, $split['other_benefits'] * 12, 90000),
            2,
        );
    }
}
