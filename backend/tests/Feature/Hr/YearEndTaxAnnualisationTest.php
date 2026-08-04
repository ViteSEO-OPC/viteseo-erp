<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * FIN-028 — the year-end withholding true-up.
 *
 * Every period withholds by ANNUALISING that period: this cutoff's taxable pay × periods per year,
 * taxed, divided back down. Correct when you cannot see the future, but it is a projection, and it
 * is only right if every period is identical. They never are — overtime in one month and none the
 * next, a raise in August, unpaid days in June. Twelve projections of a year are not a year, so
 * the sum of what was withheld drifts from the tax actually due.
 *
 * Nobody notices during the year. It surfaces when the employee files, or when the company issues
 * BIR 2316 and the figures disagree with what it remitted.
 *
 * THE ASSERTION THAT MATTERS is the first one: after the December run, total withheld across the
 * year equals the annual tax due on the year's actual taxable pay. Everything else here guards the
 * ways that could be got wrong — a refund that gets clamped at zero, a re-run that settles the
 * year against its own previous attempt, and every other cycle quietly acquiring an adjustment.
 */
class YearEndTaxAnnualisationTest extends HrTestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        // December 2026 must be wholly in the past for the final run to price it.
        $this->travelTo(Carbon::parse('2027-01-15 12:00', config('app.timezone_local')));

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
            'base_salary' => 40000, 'pay_frequency' => 'monthly', 'is_management' => false,
        ]);
    }

    /**
     * January–November written directly, with a DELIBERATELY UNEVEN taxable pattern — that is the
     * whole reason a true-up is needed. `withholding_tax` is what each period's projection would
     * have taken on that period's own figure.
     */
    private function payJanToNov(array $monthlyTaxable): void
    {
        $tax = app(TaxService::class);

        foreach (range(1, 11) as $month) {
            $start = sprintf('%d-%02d-01', self::YEAR, $month);
            $taxable = $monthlyTaxable[$month - 1];

            $run = PayrollRun::firstOrCreate(
                ['cycle_start' => $start, 'cycle_end' => date('Y-m-t', strtotime($start)), 'frequency' => 'monthly'],
                ['type' => 'regular', 'status' => 'completed', 'run_at' => now()],
            );

            Payslip::create([
                'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
                'base_salary' => $taxable, 'gross' => $taxable,
                'taxable_pay' => $taxable,
                // What annualising THIS period alone would have withheld — the projection.
                'withholding_tax' => round($tax->annualTax($taxable * 12, "{$start}") / 12, 2),
                'total_deductions' => 0, 'net_pay' => $taxable, 'status' => 'disbursed',
            ]);
        }
    }

    /**
     * Present on every December weekday, so the closing run prices a real month.
     *
     * Without this the December run finds no attendance, treats every workday as unpaid, and pays
     * almost nothing — which makes the year's actual taxable far lower than the Jan–Nov
     * projections assumed and turns every scenario into a large refund. That is correct behaviour
     * for an employee who did not work, but it is not the case under test.
     */
    private function workedDecember(): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse('2026-12-31', $tz);

        for ($d = Carbon::parse('2026-12-01', $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function runDecember(): PayrollRun
    {
        return app(PayrollService::class)->run(
            '2026-12-01', '2026-12-31', 'monthly', User::factory()->create(),
        );
    }

    private function decemberSlip(): Payslip
    {
        return Payslip::where('employee_id', $this->employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('cycle_start', '2026-12-01')
                ->where('frequency', 'monthly'))
            ->firstOrFail();
    }

    /** @return array{taxable: float, withheld: float} */
    private function yearTotals(): array
    {
        $slips = Payslip::where('employee_id', $this->employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('type', 'regular')
                ->whereYear('cycle_start', self::YEAR))
            ->get();

        return [
            'taxable' => round((float) $slips->sum('taxable_pay'), 2),
            'withheld' => round((float) $slips->sum('withholding_tax'), 2),
        ];
    }

    // --- the point of the whole feature ---------------------------------------------------

    public function test_an_uneven_year_ends_correctly_withheld(): void
    {
        // Big overtime months early, quiet months later — projections run high, then low.
        $this->payJanToNov([80000, 80000, 80000, 40000, 40000, 40000, 40000, 40000, 40000, 40000, 40000]);

        $this->workedDecember();
        $this->runDecember();

        $totals = $this->yearTotals();
        $due = app(TaxService::class)->annualTax($totals['taxable'], '2026-12-31');

        $this->assertEqualsWithDelta(
            $due,
            $totals['withheld'],
            0.05,
            'the year closed with the wrong total withheld — the December true-up did not settle it',
        );
    }

    /** Over-withholding is the common case, and the correction is a refund, not a floor at zero. */
    public function test_over_withholding_is_refunded_on_the_december_payslip(): void
    {
        // A very high start projects a high bracket the year never reaches.
        $this->payJanToNov([200000, 200000, 200000, 20000, 20000, 20000, 20000, 20000, 20000, 20000, 20000]);

        $this->workedDecember();
        $this->runDecember();
        $slip = $this->decemberSlip();

        $this->assertLessThan(0, (float) $slip->tax_annualisation, 'the year-end correction should be a refund');
        $this->assertLessThan(0, (float) $slip->withholding_tax, 'a refund means negative withholding on this payslip');
        $this->assertGreaterThan(
            (float) $slip->gross,
            (float) $slip->net_pay,
            'a refund must make net pay exceed gross — nothing may clamp it at zero',
        );
    }

    /**
     * Under-withholding is collected on the same payslip — but the scenario has to be constructed,
     * and WHY is worth stating.
     *
     * Per-period annualisation is structurally biased toward OVER-withholding. The tax table is
     * convex (rates rise with income), and withholding takes the average of `f(12 × period)` while
     * the year owes `f(Σ periods)`. By Jensen's inequality the first is always ≥ the second, so
     * uneven pay alone can never under-withhold — it only ever over-withholds, and refunds are the
     * normal year-end outcome.
     *
     * Collection happens in the flat region the inequality does not bind: the exempt band. Each
     * month here annualises to ₱240,000, below the ₱250,000 threshold, so every period withholds
     * exactly zero — while the year's total crosses the threshold and genuinely owes tax. Without
     * a true-up this employee would end the year having paid nothing on income that was taxable.
     */
    public function test_tax_owed_but_never_withheld_is_collected_in_december(): void
    {
        // ₱20,000/month → ₱240,000 annualised → zero withheld, every single period.
        $this->payJanToNov(array_fill(0, 11, 20000));

        $this->workedDecember();
        $this->runDecember();

        $totals = $this->yearTotals();
        $due = app(TaxService::class)->annualTax($totals['taxable'], '2026-12-31');

        $this->assertGreaterThan(250000, $totals['taxable'], 'precondition: the year crosses the exempt band');
        $this->assertGreaterThan(0, $due, 'precondition: the year genuinely owes tax');
        $this->assertGreaterThan(
            0,
            (float) $this->decemberSlip()->withholding_tax,
            'tax owed for the year was never collected',
        );
        $this->assertEqualsWithDelta($due, $totals['withheld'], 0.05);
    }

    // --- the ways this could be got wrong --------------------------------------------------

    /** Only the closing cycle settles. Every other period keeps projecting, as it must. */
    public function test_an_ordinary_cycle_carries_no_adjustment(): void
    {
        $march = app(PayrollService::class)->run(
            '2026-03-01', '2026-03-31', 'monthly', User::factory()->create(),
        );

        $slip = Payslip::where('payroll_run_id', $march->id)->firstOrFail();

        $this->assertSame(0.0, (float) $slip->tax_annualisation);
    }

    /**
     * A re-run must not settle the year against its OWN previous attempt — that would count
     * December's withholding as "already withheld" and drift further on every recompute.
     */
    public function test_re_running_december_settles_to_the_same_figure(): void
    {
        $this->payJanToNov([80000, 80000, 80000, 40000, 40000, 40000, 40000, 40000, 40000, 40000, 40000]);

        $this->workedDecember();
        $this->runDecember();
        $first = (float) $this->decemberSlip()->withholding_tax;

        $this->runDecember();
        $this->runDecember();

        $this->assertEqualsWithDelta(
            $first,
            (float) $this->decemberSlip()->withholding_tax,
            0.01,
            'the true-up drifted across re-runs — it is counting its own prior attempt',
        );
    }

    /** A perfectly even year needs no meaningful correction — the projection was already right. */
    public function test_an_even_year_needs_almost_no_correction(): void
    {
        $this->payJanToNov(array_fill(0, 11, 40000));

        $this->workedDecember();
        $this->runDecember();

        $this->assertEqualsWithDelta(
            0,
            (float) $this->decemberSlip()->tax_annualisation,
            50,
            'an even year should not need a large year-end adjustment',
        );
    }
}
