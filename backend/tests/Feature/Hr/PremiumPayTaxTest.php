<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** (2026-07-28 audit) — premium pay is taxable compensation. */
class PremiumPayTaxTest extends HrTestCase
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
        // A salary high enough to sit above the ₱250k tax-free band, so tax actually moves.
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** @return array<string, float> */
    private function slip(float $holiday = 0, float $ot = 0, float $night = 0, float $restDay = 0): array
    {
        return app(PayrollService::class)->payslipFor(
            employee: $this->employee,
            reimb: 0.0,
            unpaidDays: 0.0,
            periodsPerYear: 24,
            workingDaysInPeriod: 11,
            dist: ['index' => 0, 'count' => 2, 'deduction_timing' => 'every', 'tax_timing' => 'every'],
            holidayPremium: $holiday,
            overtimePay: $ot,
            nightDiffPay: $night,
            restDayPremium: $restDay,
        );
    }

    /** THE BUG: each premium line must raise withholding. */
    public function test_each_premium_line_increases_withholding(): void
    {
        $base = $this->slip()['withholding_tax'];
        $this->assertGreaterThan(0, $base, 'the baseline must be taxed for this test to mean anything');

        foreach ([
            'holiday premium' => $this->slip(holiday: 5000),
            'overtime' => $this->slip(ot: 5000),
            'night differential' => $this->slip(night: 5000),
            'rest-day premium' => $this->slip(restDay: 5000),
        ] as $label => $slip) {
            $this->assertGreaterThan($base, $slip['withholding_tax'], "{$label} did not raise withholding");
        }
    }

    /** The same peso amount is taxed the same whichever premium line it arrived on. */
    public function test_the_premium_lines_are_taxed_identically(): void
    {
        $tax = fn (array $s) => $s['withholding_tax'];

        $this->assertSame($tax($this->slip(holiday: 3000)), $tax($this->slip(ot: 3000)));
        $this->assertSame($tax($this->slip(ot: 3000)), $tax($this->slip(night: 3000)));
        $this->assertSame($tax($this->slip(night: 3000)), $tax($this->slip(restDay: 3000)));
    }

    /** A period with no premium pay is completely unchanged. */
    public function test_a_plain_period_is_unaffected(): void
    {
        $slip = $this->slip();

        // Base pay = 40,000 × 12 ÷ 24 = 20,000 → taxable monthly is the full 40,000 salary.
        $this->assertSame(20000.0, $slip['base_salary']);
        $this->assertSame(20000.0, $slip['gross']);
    }

    /** Premiums are in gross AND in the tax base — not double-counted, just both. */
    public function test_premium_pay_reaches_gross_and_the_tax_base(): void
    {
        $plain = $this->slip();
        $withOt = $this->slip(ot: 5000);

        $this->assertSame($plain['gross'] + 5000.0, $withOt['gross']);
        $this->assertGreaterThan($plain['total_deductions'], $withOt['total_deductions']);
    }

    // --- de-minimis ceiling ---------------------------------------------------------

    private function withAllowances(float $monthly, float $cap, ?float $ceiling = null): array
    {
        // Allowances are read from Company → Benefits, not the employee's columns.
        // `$ceiling` is this BENEFIT's own de-minimis limit; `$cap` is the separate
        // ₱90,000 exemption for 13th month pay and other benefits, which the excess joins.
        $this->employee->benefitPrograms()->detach();
        if ($monthly > 0) {
            $program = \App\Modules\Hr\Models\BenefitProgram::create([
                'name' => 'Meal Allowance ' . uniqid(), 'category' => 'allowance', 'kind' => 'allowance',
                'amount' => $monthly, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
                'deminimis_ceiling' => $ceiling,
                'ceiling_period' => $ceiling === null ? null : 'monthly',
                'eligibility' => 'all',
            ]);
            $this->employee->benefitPrograms()->attach($program->id);
        }
        PayrollSetting::current()->update(['other_benefits_annual_cap' => $cap]);

        return app(PayrollService::class)->payslipFor(
            employee: $this->employee->fresh(),
            reimb: 0.0,
            unpaidDays: 0.0,
            periodsPerYear: 24,
            workingDaysInPeriod: 11,
            dist: [
                'index' => 0, 'count' => 2, 'deduction_timing' => 'every', 'tax_timing' => 'every',
                'other_benefits_annual_cap' => $cap,
            ],
        );
    }

    /** No ceiling recorded on the benefit → entirely tax-free, exactly as before. */
    public function test_a_benefit_with_no_ceiling_is_not_taxed(): void
    {
        // The ₱90,000 exemption always exists (TRAIN) — B17 made the column NOT NULL for that
        // reason. What makes this allowance tax-free is having no ceiling of its OWN, so nothing
        // of it ever enters the bucket.
        $none = $this->withAllowances(0, 90000);
        $withAllowance = $this->withAllowances(5000, 90000);

        $this->assertSame($none['withholding_tax'], $withAllowance['withholding_tax']);
        // …but it still reaches gross.
        $this->assertGreaterThan($none['gross'], $withAllowance['gross']);
    }

    /** Under the benefit's own ceiling → tax-free, and the ₱90,000 bucket never comes into it. */
    public function test_an_amount_under_the_benefits_own_ceiling_is_not_taxed(): void
    {
        $none = $this->withAllowances(0, 90000);
        $under = $this->withAllowances(2000, 90000, 2500); // rice, under its ₱2,500 ceiling

        $this->assertSame($none['withholding_tax'], $under['withholding_tax']);
    }

    /**
     * REWRITTEN FOR B16. This asserted the old rule — that anything above a single pooled cap was
     * taxed immediately. It is not: the excess over a benefit's own ceiling joins the ₱90,000
     * exemption for 13th month pay and other benefits, and only what is over THAT is taxable.
     */
    public function test_an_excess_under_the_annual_bucket_is_still_not_taxed(): void
    {
        $none = $this->withAllowances(0, 90000);
        // ₱500/month over the ceiling ⇒ ₱6,000 a year, nowhere near ₱90,000.
        $over = $this->withAllowances(3000, 90000, 2500);

        $this->assertSame(
            $none['withholding_tax'],
            $over['withholding_tax'],
            'A small excess used to be taxed in full — that over-withheld for almost everybody.',
        );
    }

    /** Only what exceeds the ₱90,000 bucket is compensation. */
    public function test_only_the_amount_over_the_annual_bucket_is_taxed(): void
    {
        $under = $this->withAllowances(3000, 90000, 2500);   // ₱6,000/yr into the bucket
        $over = $this->withAllowances(15000, 90000, 2500);   // ₱150,000/yr ⇒ ₱60,000 over

        $this->assertGreaterThan($under['withholding_tax'], $over['withholding_tax']);
    }
}
