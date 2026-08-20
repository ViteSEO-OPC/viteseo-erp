<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** 13th month pay is paid by payroll and withheld on. */
class ThirteenthMonthRunTest extends HrTestCase
{
    use RefreshDatabase;

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

    private function employee(string $no, float $salary): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => $salary, 'pay_frequency' => 'monthly', 'is_management' => false,
            'employment_status' => 'regular',
        ]);
    }

    /** A year of ordinary payslips, written directly — this slice is about what December does. */
    private function payYear(Employee $employee, float $monthlyBasic, float $otherBenefits = 0, float $taxablePay = 0): void
    {
        foreach (range(1, 12) as $month) {
            $start = sprintf('2026-%02d-01', $month);
            $run = PayrollRun::firstOrCreate(
                ['cycle_start' => $start, 'cycle_end' => date('Y-m-t', strtotime($start)), 'frequency' => 'monthly'],
                ['type' => 'regular', 'status' => 'completed', 'run_at' => now()],
            );

            Payslip::create([
                'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                'base_salary' => $monthlyBasic, 'gross' => $monthlyBasic,
                'other_benefits' => $otherBenefits, 'taxable_pay' => $taxablePay,
                'total_deductions' => 0, 'net_pay' => $monthlyBasic, 'status' => 'disbursed',
            ]);
        }
    }

    /** Named `execute`, not `run` — PHPUnit's TestCase::run() is final. */
    private function execute(int $year = 2026): PayrollRun
    {
        return app(PayrollService::class)->runThirteenthMonth($year, User::factory()->create());
    }

    // --- it is paid ------------------------------------------------------------------

    public function test_the_year_is_paid_as_basic_over_twelve(): void
    {
        $employee = $this->employee('E-001', 30000);
        $this->payYear($employee, 30000);

        $run = $this->execute();
        $slip = Payslip::where('payroll_run_id', $run->id)->firstOrFail();

        $this->assertSame('thirteenth_month', $run->type);
        $this->assertSame(30000.0, (float) $slip->thirteenth_month, 'Σ basic ÷ 12');
        $this->assertSame(30000.0, (float) $slip->gross);
    }

    /** Under ₱90,000 ⇒ nothing withheld. Most employees, most years. */
    public function test_a_13th_month_under_the_exemption_is_not_taxed(): void
    {
        $employee = $this->employee('E-001', 30000);
        $this->payYear($employee, 30000, 0, 20000);

        $slip = Payslip::where('payroll_run_id', $this->execute()->id)->firstOrFail();

        $this->assertSame(0.0, (float) $slip->withholding_tax);
        $this->assertSame(30000.0, (float) $slip->net_pay);
    }

    /** THE GAP THIS CLOSES: over ₱90,000, the excess is taxed. It never was. */
    public function test_only_the_amount_over_the_exemption_is_taxed(): void
    {
        $employee = $this->employee('E-001', 120000);
        $this->payYear($employee, 120000, 0, 100000); // ₱1.2M taxable for the year

        $slip = Payslip::where('payroll_run_id', $this->execute()->id)->firstOrFail();

        // 13th month ₱120,000 ⇒ ₱30,000 over the ₱90,000 exemption, taxed at the employee's
        // MARGINAL rate on top of what they have already been taxed on (₱1.2M ⇒ 25% band).
        $this->assertSame(120000.0, (float) $slip->thirteenth_month);
        $this->assertSame(7500.0, (float) $slip->withholding_tax, '25% of the ₱30,000 excess');
        $this->assertSame(112500.0, (float) $slip->net_pay);
    }

    /** The bucket is shared — excess de minimis eats into the same ₱90,000. */
    public function test_the_years_other_benefits_use_up_the_same_exemption(): void
    {
        $employee = $this->employee('E-001', 85000);
        // ₱85,000 13th month is under ₱90,000 on its own; ₱1,000/month of excess de minimis
        // (₱12,000 for the year) pushes the bucket to ₱97,000.
        $this->payYear($employee, 85000, 1000, 70000);

        $slip = Payslip::where('payroll_run_id', $this->execute()->id)->firstOrFail();

        $this->assertGreaterThan(
            0.0,
            (float) $slip->withholding_tax,
            'Allowance excess counts toward the same exemption — that is the point of one bucket.',
        );
    }

    // --- it behaves like a payroll run ------------------------------------------------

    public function test_re_running_recomputes_in_place_rather_than_paying_twice(): void
    {
        $employee = $this->employee('E-001', 30000);
        $this->payYear($employee, 30000);

        $first = $this->execute();
        $second = $this->execute();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payslip::where('payroll_run_id', $second->id)->count());
        $this->assertSame(30000.0, (float) $second->total_gross, 'a re-run must not compound');
    }

    /** Statutory contributions are charged on monthly pay and are not levied again here. */
    public function test_no_statutory_contributions_are_charged_on_it(): void
    {
        $employee = $this->employee('E-001', 30000);
        $this->payYear($employee, 30000);

        $slip = Payslip::where('payroll_run_id', $this->execute()->id)->firstOrFail();

        $this->assertSame(0.0, (float) $slip->sss);
        $this->assertSame(0.0, (float) $slip->philhealth);
        $this->assertSame(0.0, (float) $slip->pagibig);
    }

    /** The confirmation quotes these figures, so they must be the run's — and must save nothing. */
    public function test_the_preview_matches_the_run_and_writes_nothing(): void
    {
        $employee = $this->employee('E-001', 120000);
        $this->payYear($employee, 120000, 0, 100000);

        $runsBefore = PayrollRun::count();

        $preview = $this->actingAsHr()
            ->getJson('/api/v1/hr/payroll/thirteenth-month/preview?year=2026')
            ->assertOk()
            ->json();

        $this->assertSame($runsBefore, PayrollRun::count(), 'a preview must not create a run');
        $this->assertFalse($preview['meta']['already_run']);

        $slip = Payslip::where('payroll_run_id', $this->execute()->id)->firstOrFail();

        $this->assertSame((float) $slip->thirteenth_month, (float) $preview['data']['gross']);
        $this->assertSame((float) $slip->withholding_tax, (float) $preview['data']['tax']);

        // …and once it has run, the screen must stop offering "Pay" and start offering "Recompute".
        $after = $this->actingAsHr()
            ->getJson('/api/v1/hr/payroll/thirteenth-month/preview?year=2026')
            ->assertOk()->json();
        $this->assertTrue($after['meta']['already_run']);
    }

    public function test_the_endpoint_runs_it_and_is_hr_only(): void
    {
        $employee = $this->employee('E-001', 30000);
        $this->payYear($employee, 30000);

        $this->actingAsHr()->postJson('/api/v1/hr/payroll/thirteenth-month/run', ['year' => 2026])
            ->assertOk();

        $this->actingAs($employee->user)
            ->postJson('/api/v1/hr/payroll/thirteenth-month/run', ['year' => 2026])
            ->assertForbidden();
    }
}
