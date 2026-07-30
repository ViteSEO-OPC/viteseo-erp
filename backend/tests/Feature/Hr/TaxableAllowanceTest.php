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
 * Slice B4 — the de-minimis / taxable switch on an allowance actually taxes.
 *
 * Every allowance programme has this switch and payroll ignored it: everything was treated as
 * tax-free. Nothing was marked taxable yet, so nobody was affected — but it was a control that
 * did nothing, and the first time HR used it the company would have under-withheld silently.
 *
 * The two halves are taxed on different rules, which is why they cannot share a number:
 *   de_minimis → tax-free up to the company's annual ceiling; only the EXCESS is compensation
 *   taxable    → compensation in full, ceiling does not apply
 *
 * Gross pay is identical under both. Only withholding moves.
 */
class TaxableAllowanceTest extends HrTestCase
{
    use RefreshDatabase;

    /** Well clear of the amounts here, so the ceiling only bites in the tests that set it low. */
    private const CAP = 90000;

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

        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function employee(string $no): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /**
     * `custom` because these tests compare two employees side by side, and eligibility is now a
     * RULE — an `all` programme would cover both of them and the comparison would be of one
     * employee against themselves. `custom` is the mode that means "these named people", which is
     * exactly what a two-employee comparison needs.
     */
    private function programme(string $name, float $amount, string $treatment): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => $name, 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => $amount, 'frequency' => 'monthly', 'tax_treatment' => $treatment,
            'eligibility' => 'custom', 'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function slip(Employee $employee, mixed $cap = self::CAP): array
    {
        return app(PayrollService::class)->payslipFor(
            employee: $employee->fresh(), reimb: 0.0, unpaidDays: 0.0,
            periodsPerYear: 24, workingDaysInPeriod: 11,
            dist: [
                'index' => 0, 'count' => 2, 'deduction_timing' => 'every', 'tax_timing' => 'every',
                'cycle_end' => '2026-07-31', 'deminimis_annual_cap' => $cap,
            ],
        );
    }

    // --- THE POINT OF THE SLICE ------------------------------------------------------

    /** Same money, same gross — but the taxable one withholds more. */
    public function test_a_taxable_allowance_withholds_more_than_a_de_minimis_one(): void
    {
        $free = $this->employee('E-FREE');
        $free->benefitPrograms()->attach($this->programme('Meal', 5000, 'de_minimis')->id);

        $taxed = $this->employee('E-TAX');
        $taxed->benefitPrograms()->attach($this->programme('Bonus Allowance', 5000, 'taxable')->id);

        $a = $this->slip($free);
        $b = $this->slip($taxed);

        $this->assertSame($a['allowances'], $b['allowances'], 'the same allowance is paid');
        $this->assertSame($a['gross'], $b['gross'], 'gross pay must not move — only withholding');
        $this->assertGreaterThan(
            $a['withholding_tax'],
            $b['withholding_tax'],
            'a taxable allowance is compensation; before Slice B4 these were equal',
        );
    }

    /** The regression guard: flipping the switch must change the payslip. */
    public function test_flipping_the_switch_changes_withholding(): void
    {
        $employee = $this->employee('E-001');
        $programme = $this->programme('Meal', 5000, 'de_minimis');
        $employee->benefitPrograms()->attach($programme->id);

        $before = $this->slip($employee)['withholding_tax'];
        $programme->update(['tax_treatment' => 'taxable']);
        $after = $this->slip($employee)['withholding_tax'];

        $this->assertNotSame($before, $after, 'the switch did nothing before Slice B4');
    }

    // --- the ceiling belongs to the de-minimis side only -----------------------------

    /**
     * A taxable allowance must not eat the tax-free room that belongs to the de-minimis ones.
     *
     * Counting it toward the ceiling would push the de-minimis total over the line and tax that
     * excess as well — taxing the same money twice.
     */
    public function test_a_taxable_allowance_does_not_consume_the_de_minimis_ceiling(): void
    {
        // Ceiling ₱24,000/yr = ₱2,000/mo. De-minimis is ₱2,000 — exactly at the line, nothing
        // over. The ₱3,000 taxable allowance must not push it over.
        $employee = $this->employee('E-001');
        $employee->benefitPrograms()->attach($this->programme('Meal', 2000, 'de_minimis')->id);
        $employee->benefitPrograms()->attach($this->programme('Bonus', 3000, 'taxable')->id);
        $mixed = $this->slip($employee, 24000)['withholding_tax'];

        // The same total, with the taxable part as the ONLY thing that should be taxed.
        $control = $this->employee('E-002');
        $control->benefitPrograms()->attach($this->programme('Meal B', 2000, 'de_minimis')->id);
        $control->benefitPrograms()->attach($this->programme('Bonus B', 3000, 'taxable')->id);

        // Raising the ceiling changes nothing, because the de-minimis side was never over it.
        $this->assertSame($mixed, $this->slip($control, 90000)['withholding_tax']);
    }

    /** The de-minimis excess is still taxed — Slice 10's rule survives the split. */
    public function test_de_minimis_above_the_ceiling_is_still_taxed(): void
    {
        $employee = $this->employee('E-001');
        $employee->benefitPrograms()->attach($this->programme('Meal', 5000, 'de_minimis')->id);

        // ₱5,000/mo = ₱60,000/yr. A ₱12,000 ceiling leaves ₱48,000 taxable.
        $tight = $this->slip($employee, 12000)['withholding_tax'];
        $loose = $this->slip($employee, 90000)['withholding_tax'];

        $this->assertGreaterThan($loose, $tight, 'the excess above the ceiling is compensation');
    }

    /** No ceiling set means the de-minimis side is entirely tax-free, as before. */
    public function test_no_ceiling_leaves_de_minimis_untaxed_but_still_taxes_the_taxable_part(): void
    {
        $free = $this->employee('E-FREE');
        $free->benefitPrograms()->attach($this->programme('Meal', 5000, 'de_minimis')->id);

        $taxed = $this->employee('E-TAX');
        $taxed->benefitPrograms()->attach($this->programme('Bonus', 5000, 'taxable')->id);

        $bare = $this->employee('E-NONE');

        $this->assertSame(
            $this->slip($bare, null)['withholding_tax'],
            $this->slip($free, null)['withholding_tax'],
            'no ceiling ⇒ a de-minimis allowance adds nothing to the tax base',
        );
        $this->assertGreaterThan(
            $this->slip($bare, null)['withholding_tax'],
            $this->slip($taxed, null)['withholding_tax'],
            'a taxable allowance is taxed with or without a ceiling',
        );
    }

    // --- the split itself ------------------------------------------------------------

    public function test_the_split_sums_to_the_total(): void
    {
        $employee = $this->employee('E-001');
        $employee->benefitPrograms()->attach($this->programme('Meal', 2000, 'de_minimis')->id);
        $employee->benefitPrograms()->attach($this->programme('Transport', 1500, 'de_minimis')->id);
        $employee->benefitPrograms()->attach($this->programme('Bonus', 3000, 'taxable')->id);

        $service = app(AllowanceService::class);
        $split = $service->monthlyByTaxTreatmentFor($employee->fresh());

        $this->assertSame(3500.0, $split['de_minimis']);
        $this->assertSame(3000.0, $split['taxable']);
        $this->assertSame(
            $service->monthlyFor($employee->fresh()),
            round($split['de_minimis'] + $split['taxable'], 2),
            'the split must not lose or invent a centavo of gross pay',
        );
    }

    /** An unrecognised value must not be guessed as taxable — that would over-withhold. */
    public function test_an_unknown_tax_treatment_is_treated_as_de_minimis(): void
    {
        $employee = $this->employee('E-001');
        $programme = $this->programme('Meal', 2000, 'de_minimis');
        $employee->benefitPrograms()->attach($programme->id);
        // Straight to the column: the request layer only allows the two valid values.
        $programme->forceFill(['tax_treatment' => 'something_else'])->save();

        $split = app(AllowanceService::class)->monthlyByTaxTreatmentFor($employee->fresh());

        $this->assertSame(2000.0, $split['de_minimis']);
        $this->assertSame(0.0, $split['taxable']);
    }
}
