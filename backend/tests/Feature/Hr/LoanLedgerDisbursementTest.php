<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\LoanPayment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * FIN-003 (payroll audit, §3) — the loan ledger and the payslip disagree about when money moved.
 *
 * WRITTEN TO FAIL. Like FIN-001, this finding was reached by tracing control flow rather than by
 * observing a run, and the audit's instruction is to reproduce it before fixing it. If a test here
 * passes on unmodified code, that half of the finding is wrong and should be struck.
 *
 * EmployeeLoanTest already covers the design that WORKS: no stored balance, one payment row per
 * (loan, run), and a re-run that replaces rather than accumulates. This file covers the two places
 * that invariant breaks.
 *
 * (a) COMPUTE CREDITS THE LOAN. `collectForPeriod()` writes a `LoanPayment` while the payslip is
 *     still `computed` — before anyone asserts the money was paid. `reopenPayslip()` reverses the
 *     payslip and its reimbursements but never the loan payment, so reversing a payment leaves the
 *     employee's debt written down against money the company did not pay. Slice 12 established
 *     exactly this invariant for reimbursements; loans never got it.
 *
 * (b) RE-RUNNING THE SETTLING PERIOD STRANDS THE LOAN. `collectForPeriod()` loads only
 *     `status = 'active'` loans, then deletes this run's payment rows for any loan NOT in that
 *     set. A loan that the previous compute of the SAME run marked `settled` is therefore excluded
 *     — so its final payment row is deleted while the loan stays `settled`. The balance reappears
 *     but `isCollectible()` is false, so payroll can never collect it again.
 */
class LoanLedgerDisbursementTest extends HrTestCase
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

        $this->workedEveryDay(); // otherwise every day is unpaid and there is no net to collect from
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

    private function runPayroll(): PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    private function slip(): Payslip
    {
        return Payslip::where('employee_id', $this->employee->id)->firstOrFail();
    }

    // --- (a) compute credits the loan; reopening does not reverse it ---------------------

    /** Reversing a payslip must return the instalment it collected to the outstanding balance. */
    public function test_reopening_a_payslip_reverses_its_loan_payment(): void
    {
        $loan = $this->loan(12000, 1000);

        $this->runPayroll();
        $this->assertSame(1000.0, $loan->fresh()->paid(), 'precondition: the instalment was collected');

        $payroll = app(PayrollService::class);
        $payroll->markDone($this->slip());
        $payroll->reopenPayslip($this->slip(), 'wrong bank details');

        $this->assertSame(0.0, $loan->fresh()->paid(), 'the loan is still credited for a payslip that was un-paid');
        $this->assertSame(12000.0, $loan->fresh()->outstanding());
    }

    /** And a loan the reversed payslip had settled must become collectible again. */
    public function test_reopening_a_payslip_returns_a_settled_loan_to_active(): void
    {
        $loan = $this->loan(800, 1000); // one period covers it

        $this->runPayroll();
        $this->assertSame('settled', $loan->fresh()->status, 'precondition: the loan settled');

        $payroll = app(PayrollService::class);
        $payroll->markDone($this->slip());
        $payroll->reopenPayslip($this->slip(), 'wrong bank details');

        $this->assertSame('active', $loan->fresh()->status, 'the loan stayed settled on money that was un-paid');
        $this->assertSame(800.0, $loan->fresh()->outstanding());
    }

    // --- (b) re-running the settling period strands the loan -----------------------------

    /**
     * THE DESIGN POINT, restated for the settling period. EmployeeLoanTest proves a re-run does not
     * double-collect; this proves the opposite failure — a re-run that silently un-collects.
     */
    public function test_re_running_the_settling_period_keeps_the_final_payment(): void
    {
        $loan = $this->loan(800, 1000);

        $run = $this->runPayroll();
        $this->assertSame('settled', $loan->fresh()->status, 'precondition: the loan settled');
        $this->assertSame(1, LoanPayment::where('loan_id', $loan->id)->count(), 'precondition: one payment row');

        $this->runPayroll();

        $this->assertSame(
            1,
            LoanPayment::where('loan_id', $loan->id)->where('payroll_run_id', $run->id)->count(),
            'the re-run deleted the payment that settled the loan',
        );
        $this->assertSame(800.0, $loan->fresh()->paid());
        $this->assertSame(0.0, $loan->fresh()->outstanding(), 'the debt reappeared after a re-run');
        $this->assertSame('settled', $loan->fresh()->status);
    }

    /**
     * The consequence, stated as the thing that actually hurts: the balance is back but nothing
     * will ever collect it, because `isCollectible()` requires `active`.
     */
    public function test_a_stranded_loan_is_never_left_owing_but_uncollectible(): void
    {
        $loan = $this->loan(800, 1000);

        $this->runPayroll();
        $this->runPayroll();

        $fresh = $loan->fresh();

        $this->assertFalse(
            $fresh->outstanding() > 0 && ! $fresh->isCollectible(),
            'the loan owes money that payroll can never collect again',
        );
    }
}
