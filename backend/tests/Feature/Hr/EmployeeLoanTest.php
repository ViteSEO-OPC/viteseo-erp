<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\LoanPayment;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Slice 11b (2026-07-28 audit) — loans and cash advances repaid through payroll.
 *
 * The design point under test is RE-RUN SAFETY: a loan has no `balance` column. The balance is
 * `principal − SUM(payments)`, payments are unique per (loan, payroll run), and a recompute
 * REPLACES that period's row. A stored balance decremented per run would double-count on every
 * re-run — the failure mode Slices 5 and 12 exist to prevent.
 *
 * Shortfall rule (decision 2026-07-28): collect what the payslip can afford, carry the rest.
 */
class EmployeeLoanTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

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
        ]);

        $this->workedEveryDay(); // otherwise every day is unpaid and net pay is 0
    }

    private function workedEveryDay(): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);

        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end); $d->addDay()) {
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

    private function loan(float $principal, float $perPeriod, string $start = '2026-01-01'): EmployeeLoan
    {
        return EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => $principal, 'per_period_amount' => $perPeriod,
            'start_date' => $start, 'status' => 'active',
        ]);
    }

    private function runPayroll(): \App\Modules\Hr\Models\PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    private function slip(): Payslip
    {
        return Payslip::where('employee_id', $this->employee->id)->firstOrFail();
    }

    public function test_a_loan_instalment_is_deducted_and_reduces_net_pay(): void
    {
        $loan = $this->loan(12000, 1000);
        $this->runPayroll();

        $slip = $this->slip();
        $this->assertSame(1000.0, (float) $slip->other_deductions);
        $this->assertSame(1000.0, $loan->fresh()->paid());
        $this->assertSame(11000.0, $loan->fresh()->outstanding());
    }

    /** THE DESIGN POINT: re-running must not collect twice. */
    public function test_re_running_does_not_double_deduct(): void
    {
        $loan = $this->loan(12000, 1000);

        $this->runPayroll();
        $this->runPayroll();
        $this->runPayroll();

        $this->assertSame(1000.0, $loan->fresh()->paid());
        $this->assertSame(11000.0, $loan->fresh()->outstanding());
        $this->assertSame(1, LoanPayment::where('loan_id', $loan->id)->count());
        $this->assertSame(1000.0, (float) $this->slip()->other_deductions);
    }

    /** The balance is derived, so it tracks the ledger exactly. */
    public function test_the_balance_is_derived_from_the_ledger(): void
    {
        $loan = $this->loan(12000, 1000);
        $run = $this->runPayroll();

        LoanPayment::where('loan_id', $loan->id)->where('payroll_run_id', $run->id)->delete();

        $this->assertSame(0.0, $loan->fresh()->paid());
        $this->assertSame(12000.0, $loan->fresh()->outstanding());
    }

    /** A loan settles automatically when the balance reaches zero. */
    public function test_the_final_instalment_settles_the_loan(): void
    {
        $loan = $this->loan(800, 1000); // one period covers it
        $this->runPayroll();

        $this->assertSame(800.0, $loan->fresh()->paid(), 'must not collect more than is owed');
        $this->assertSame('settled', $loan->fresh()->status);
        $this->assertSame(0.0, $loan->fresh()->outstanding());
    }

    /** SHORTFALL: take what the payslip can afford, carry the rest. */
    public function test_a_shortfall_collects_what_it_can_and_carries_the_rest(): void
    {
        $net = (float) tap($this->runPayroll(), fn () => null)->total_net;
        $this->assertGreaterThan(0, $net);

        // An instalment larger than the whole payslip.
        $loan = $this->loan($net * 3, $net * 2);
        $this->runPayroll();

        $slip = $this->slip();
        $this->assertSame($net, (float) $slip->other_deductions, 'collected exactly what was available');
        $this->assertSame(0.0, (float) $slip->net_pay, 'net pay floors at zero, never negative');
        $this->assertSame($net, $loan->fresh()->paid());
        $this->assertGreaterThan(0, $loan->fresh()->outstanding(), 'the remainder carries');
    }

    public function test_a_loan_starting_after_the_cycle_is_not_collected(): void
    {
        $loan = $this->loan(12000, 1000, start: '2026-06-01');
        $this->runPayroll();

        $this->assertSame(0.0, $loan->fresh()->paid());
    }

    public function test_a_cancelled_loan_stops_being_collected(): void
    {
        $loan = $this->loan(12000, 1000);
        $this->runPayroll();
        $this->assertSame(1000.0, $loan->fresh()->paid());

        $loan->update(['status' => 'cancelled']);
        $this->runPayroll();

        // The stale row from the first run is cleared, so the balance is restored.
        $this->assertSame(0.0, $loan->fresh()->paid());
        $this->assertSame(0.0, (float) $this->slip()->other_deductions);
    }

    /** Several loans are collected in start-date order and itemised separately. */
    public function test_multiple_loans_are_itemised(): void
    {
        $this->loan(5000, 500, start: '2026-01-01');
        EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'cash_advance',
            'principal' => 2000, 'per_period_amount' => 200,
            'start_date' => '2026-02-01', 'status' => 'active',
        ]);

        $this->runPayroll();

        $slip = $this->slip();
        $this->assertSame(700.0, (float) $slip->other_deductions);
        $this->assertCount(2, $slip->other_deductions_breakdown);
    }

    /** Loans are post-tax — they must not change withholding. */
    public function test_a_loan_does_not_change_tax_or_gross(): void
    {
        $this->runPayroll();
        $before = $this->slip();
        [$gross, $tax] = [(float) $before->gross, (float) $before->withholding_tax];

        $this->loan(12000, 1000);
        $this->runPayroll();

        $after = $this->slip()->fresh();
        $this->assertSame($gross, (float) $after->gross);
        $this->assertSame($tax, (float) $after->withholding_tax);
    }

    // --- API ------------------------------------------------------------------------

    public function test_hr_can_record_and_cancel_a_loan(): void
    {
        $this->hrPost("/api/v1/hr/employees/{$this->employee->id}/loans", [
            'type' => 'cash_advance', 'principal' => 5000,
            'per_period_amount' => 500, 'start_date' => '2026-03-01',
        ])->assertStatus(201)->assertJsonPath('data.outstanding', 5000);

        $loan = EmployeeLoan::firstOrFail();

        $this->hrPost("/api/v1/hr/loans/{$loan->id}/cancel", ['reason' => 'Settled in cash'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_an_instalment_larger_than_the_principal_is_rejected(): void
    {
        $this->hrPost("/api/v1/hr/employees/{$this->employee->id}/loans", [
            'principal' => 1000, 'per_period_amount' => 5000, 'start_date' => '2026-03-01',
        ])->assertStatus(422)->assertJsonValidationErrors('per_period_amount');
    }
}
