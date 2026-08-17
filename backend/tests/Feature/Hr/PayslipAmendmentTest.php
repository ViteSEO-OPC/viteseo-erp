<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipAmendment;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Corrections applied to the payslip of the period they correct.
 *
 * WHAT THIS REPLACES. FIN-010 paid a correction by raising a separate run and a second payslip. The
 * money was right, but one period ended up described by two documents that referenced each other
 * nowhere: a March payslip stating a figure that was wrong, and an unexplained payslip dated April.
 *
 * The two tests that matter most here are the disbursed-balance pair and the re-run one. Amending a
 * paid payslip is the case the feature exists for, and it is only honest if the payslip keeps saying
 * what actually left the bank — otherwise every total downstream inherits a payment that never
 * happened. And a correction that a later recompute of the same period silently reverses would be
 * worse than no correction at all, because nothing would say it had gone.
 */
class PayslipAmendmentTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        // A full month present, so the period prices at full pay and the figures below are about the
        // amendment rather than about absences.
        $tz = config('app.timezone_local');
        for ($day = Carbon::parse('2026-08-01', $tz); $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(8, 0)->utc(),
                'time_out' => $day->copy()->setTime(17, 0)->utc(),
                'status' => 'present',
            ]);
        }
    }

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    /** The first August cutoff, computed but not yet paid. */
    private function openPeriod(): PayrollRun
    {
        return $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    /** The same cutoff, paid out — so it is settled and its payslip is history. */
    private function paidPeriod(): PayrollRun
    {
        $run = $this->openPeriod();

        foreach ($run->payslips as $slip) {
            $this->payroll()->markDone($slip, $this->hrUser());
        }

        return $run->fresh();
    }

    private function amend(PayrollRun $run, float $amount = 5000, ?string $note = 'Back pay for the 1 Aug raise'): PayrollRun
    {
        return $this->payroll()->amendRun(
            $run,
            'Back pay for 1–15 August after a back-dated raise',
            [['employee_id' => $this->employee->id, 'amount' => $amount, 'note' => $note]],
            null,
            $this->hrUser(),
        );
    }

    /**
     * A correction that TAKES money — either kind, both entered as a positive figure.
     *
     * The magnitude is the caller's; the direction is the kind's. That is the contract the whole
     * feature rests on, so the helper deliberately passes the amount through unsigned.
     */
    private function amendWith(PayrollRun $run, string $kind, float $amount, ?string $note = null): PayrollRun
    {
        return $this->payroll()->amendRun(
            $run,
            $kind === 'deduction'
                ? 'HMO share was not deducted for 1–15 August'
                : 'Transport allowance was paid twice for 1–15 August',
            [['employee_id' => $this->employee->id, 'amount' => $amount, 'note' => $note]],
            null,
            $this->hrUser(),
            $kind,
        );
    }

    private function slip(PayrollRun $run): Payslip
    {
        return Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();
    }

    // --- one period, one document ------------------------------------------------------------

    public function test_an_amendment_lands_on_the_periods_own_payslip(): void
    {
        $run = $this->openPeriod();
        $before = $this->slip($run);
        $grossBefore = (float) $before->gross;

        $this->amend($run);

        $after = $this->slip($run);
        $this->assertSame(5000.0, (float) $after->adjustment_pay);
        $this->assertSame(round($grossBefore + 5000, 2), (float) $after->gross);
        $this->assertSame($before->id, $after->id, 'the correction must not create a second payslip');
    }

    public function test_it_raises_no_run_and_no_payslip_of_its_own(): void
    {
        $run = $this->openPeriod();

        $this->amend($run);

        $this->assertSame(1, PayrollRun::count(), 'an amendment is not a run');
        $this->assertSame(1, Payslip::count(), 'an amendment is not a payslip');
    }

    public function test_net_pay_rises_by_the_amount_less_whatever_was_withheld_on_it(): void
    {
        $run = $this->openPeriod();
        $before = $this->slip($run);
        $taxBefore = (float) $before->withholding_tax;
        $netBefore = (float) $before->net_pay;

        $this->amend($run);
        $after = $this->slip($run);

        $tax = round((float) $after->withholding_tax - $taxBefore, 2);

        $this->assertSame(5000.0, round((float) $after->taxable_pay - (float) $before->taxable_pay, 2));
        $this->assertSame(round(5000 - $tax, 2), round((float) $after->net_pay - $netBefore, 2));
    }

    /**
     * TAX IS MARGINAL, not a fresh calculation on the amount alone — the same method the 13th month
     * uses for its excess over ₱90,000, and for the same reason: taxing ₱5,000 in isolation applies
     * the lowest bracket to money that actually sits on top of a year's earnings.
     *
     * Which is why it takes a year of earnings to see it at all. With one cutoff behind it the year
     * is still under the ₱250,000 exemption and the correct withholding on a correction is genuinely
     * nothing; the assertion is that the SAME correction costs tax once the year is above it.
     */
    public function test_a_correction_on_top_of_a_taxed_year_is_withheld_on(): void
    {
        $this->seedYearToDateTaxable(600000);

        $run = $this->openPeriod();
        $taxBefore = (float) $this->slip($run)->withholding_tax;

        $this->amend($run);

        $this->assertGreaterThan(
            0,
            round((float) $this->slip($run)->withholding_tax - $taxBefore, 2),
            'back pay on top of a taxed year is taxable compensation',
        );
    }

    /** It corrects; it does not recompute. Contributions are monthly and were already taken. */
    public function test_it_takes_no_statutory_contributions_and_leaves_basic_pay_alone(): void
    {
        $run = $this->openPeriod();
        $before = $this->slip($run);

        $this->amend($run);
        $after = $this->slip($run);

        $this->assertSame((float) $before->sss, (float) $after->sss);
        $this->assertSame((float) $before->philhealth, (float) $after->philhealth);
        $this->assertSame((float) $before->pagibig, (float) $after->pagibig);
        $this->assertSame(
            (float) $before->base_salary,
            (float) $after->base_salary,
            'back pay is not basic salary earned in this period',
        );
    }

    public function test_the_run_totals_follow_the_amended_payslips(): void
    {
        $run = $this->openPeriod();
        $netBefore = (float) $run->total_net;

        $amended = $this->amend($run);
        $slip = $this->slip($run);

        $this->assertGreaterThan($netBefore, (float) $amended->total_net);
        $this->assertSame((float) $slip->net_pay, (float) $amended->total_net);
    }

    // --- amending a payslip that has already been paid ---------------------------------------

    public function test_amending_a_paid_payslip_leaves_what_was_transferred_alone(): void
    {
        $run = $this->paidPeriod();
        $paid = (float) $this->slip($run)->net_pay;

        $this->amend($run);
        $slip = $this->slip($run);

        $this->assertSame($paid, (float) $slip->disbursed_amount, 'only this much ever left the bank');
        $this->assertGreaterThan($paid, (float) $slip->net_pay);
        $this->assertSame('disbursed', $slip->status);
    }

    public function test_the_difference_reads_as_outstanding(): void
    {
        $run = $this->paidPeriod();

        $this->amend($run);
        $slip = $this->slip($run);

        $this->assertSame(
            round((float) $slip->net_pay - (float) $slip->disbursed_amount, 2),
            $slip->amountOutstanding(),
        );
        $this->assertGreaterThan(0, $slip->amountOutstanding());
        $this->assertTrue($slip->isPartiallyPaid());
    }

    /** An unamended payslip owes nothing — the balance must not appear where there is none. */
    public function test_an_ordinary_paid_payslip_owes_nothing(): void
    {
        $slip = $this->slip($this->paidPeriod());

        $this->assertSame(0.0, $slip->amountOutstanding());
        $this->assertFalse($slip->isPartiallyPaid());
    }

    public function test_paying_the_balance_settles_it_without_a_second_payslip(): void
    {
        $run = $this->paidPeriod();
        $this->amend($run);

        $this->payroll()->markDone($this->slip($run)->fresh(), $this->hrUser());
        $slip = $this->slip($run);

        $this->assertSame(0.0, $slip->amountOutstanding());
        $this->assertSame((float) $slip->net_pay, (float) $slip->disbursed_amount);
        $this->assertSame(1, Payslip::count());
    }

    /** Reversing a disbursement puts the whole net back on the table, not just the correction. */
    public function test_reversing_a_disbursement_clears_what_was_transferred(): void
    {
        $run = $this->paidPeriod();
        $this->amend($run);

        $this->payroll()->reopenPayslip($this->slip($run)->fresh(), 'Paid the wrong account');
        $slip = $this->slip($run);

        $this->assertNull($slip->disbursed_amount);
        $this->assertSame((float) $slip->net_pay, $slip->amountOutstanding());
    }

    /**
     * A locked run can never be recomputed, so locking one that still owes money would strand the
     * balance for good — the same reason locking refuses an undisbursed payslip.
     */
    public function test_a_run_with_an_outstanding_balance_cannot_be_locked(): void
    {
        $run = $this->paidPeriod();
        $this->amend($run);

        $this->expectException(ValidationException::class);
        $this->payroll()->lock($run->fresh(), $this->hrUser());
    }

    // --- surviving a recompute ---------------------------------------------------------------

    /**
     * THE ONE THAT WOULD BE SILENT IF IT BROKE. Recomputing a period rewrites every earnings column
     * from attendance, and knows nothing about corrections — so the ledger has to be re-applied, or
     * re-running August would quietly reverse money HR had already granted.
     */
    public function test_re_running_the_period_keeps_the_amendment(): void
    {
        $run = $this->openPeriod();
        $this->amend($run);
        $amendedGross = (float) $this->slip($run)->gross;

        $rerun = $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $slip = $this->slip($rerun);

        $this->assertSame($run->id, $rerun->id, 'the same period, recomputed');
        $this->assertSame(5000.0, (float) $slip->adjustment_pay);
        $this->assertSame($amendedGross, (float) $slip->gross);
    }

    /** And re-applied, not re-added: running twice more must not compound the correction. */
    public function test_re_running_twice_does_not_double_the_amendment(): void
    {
        $run = $this->openPeriod();
        $this->amend($run);

        $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertSame(5000.0, (float) $this->slip($run)->adjustment_pay);
    }

    // --- the ledger --------------------------------------------------------------------------

    public function test_the_ledger_records_the_amount_reason_date_and_who(): void
    {
        $run = $this->openPeriod();
        $this->amend($run);

        $amendment = PayslipAmendment::where('payslip_id', $this->slip($run)->id)->firstOrFail();

        $this->assertSame(5000.0, (float) $amendment->amount);
        $this->assertStringContainsString('back-dated raise', $amendment->reason);
        $this->assertSame('Back pay for the 1 Aug raise', $amendment->note);
        // Defaulted to today in Manila, which is what the payslip shows beside the line.
        $this->assertSame('2026-08-20', $amendment->issued_on->toDateString());
        $this->assertNotNull($amendment->created_by);
    }

    public function test_two_corrections_to_one_period_are_both_kept(): void
    {
        $run = $this->openPeriod();
        $this->amend($run, 5000, 'First correction');
        $this->amend($run, 1500, 'Second correction');

        $slip = $this->slip($run);

        $this->assertSame(6500.0, (float) $slip->adjustment_pay);
        $this->assertSame(2, PayslipAmendment::where('payslip_id', $slip->id)->count());
    }

    // --- a deduction the period missed --------------------------------------------------------

    /**
     * THE DISTINCTION THE WHOLE `kind` COLUMN EXISTS FOR. A missed HMO share and pay that was never
     * earned both read as "minus ₱1,000" on a payslip and are not the same money: the deduction
     * leaves what was EARNED untouched, so gross, taxable pay and the tax withheld must not move.
     * Treating it as negative pay would refund tax the BIR is owed and understate the year.
     */
    public function test_a_missed_deduction_comes_off_net_and_leaves_pay_and_tax_alone(): void
    {
        $run = $this->openPeriod();
        $before = $this->slip($run);

        $this->amendWith($run, 'deduction', 1000, 'HMO share');
        $after = $this->slip($run);

        $this->assertSame((float) $before->gross, (float) $after->gross);
        $this->assertSame((float) $before->taxable_pay, (float) $after->taxable_pay);
        $this->assertSame((float) $before->withholding_tax, (float) $after->withholding_tax);
        $this->assertSame(0.0, (float) $after->adjustment_pay, 'a deduction is not negative earnings');

        $this->assertSame(round((float) $before->other_deductions + 1000, 2), (float) $after->other_deductions);
        $this->assertSame(round((float) $before->net_pay - 1000, 2), (float) $after->net_pay);
    }

    /** And it is NAMED where the money went, so the payslip's breakdown still foots to its total. */
    public function test_a_missed_deduction_is_itemised_on_the_payslip(): void
    {
        $run = $this->openPeriod();
        $this->amendWith($run, 'deduction', 1000, 'HMO share');

        $lines = $this->slip($run)->other_deductions_breakdown ?? [];
        $line = collect($lines)->firstWhere('amount', 1000.0);

        $this->assertNotNull($line, 'the deduction must appear as its own line');
        $this->assertStringContainsString('HMO share', $line['name']);
        $this->assertSame(
            (float) $this->slip($run)->other_deductions,
            round(collect($lines)->sum('amount'), 2),
            'the breakdown has to sum to the column it explains',
        );
    }

    public function test_the_ledger_records_a_deduction_as_negative_with_no_tax(): void
    {
        $run = $this->openPeriod();
        $this->amendWith($run, 'deduction', 1000, 'HMO share');

        $amendment = PayslipAmendment::where('payslip_id', $this->slip($run)->id)->firstOrFail();

        $this->assertSame('deduction', $amendment->kind);
        $this->assertSame(-1000.0, (float) $amendment->amount);
        $this->assertSame(0.0, (float) $amendment->tax, 'what was earned did not change, so no tax moves');
        $this->assertTrue($amendment->isDeduction());
    }

    /**
     * WHAT PAY CANNOT COVER IS CARRIED, NOT WRITTEN OFF. A deduction is money owed; a payslip too
     * thin to take it in full has to leave the rest somewhere a later period will find it, or HR
     * enters an obligation the system quietly drops.
     */
    public function test_a_deduction_larger_than_the_payslip_carries_the_rest_forward(): void
    {
        $run = $this->openPeriod();
        $net = (float) $this->slip($run)->net_pay;

        $this->amendWith($run, 'deduction', $net + 500, 'HMO arrears');
        $slip = $this->slip($run);

        $this->assertSame(0.0, (float) $slip->net_pay, 'it can take everything the payslip pays');
        $this->assertSame(500.0, (float) $slip->deduction_carryover);
        $this->assertSame(500.0, (float) ($slip->deduction_carryover_breakdown['other_deductions'] ?? 0));

        $arrears = $this->payroll()->deductionArrearsFor([$this->employee->id]);
        $this->assertSame(500.0, $arrears[$this->employee->id]['other_deductions'] ?? 0.0);
    }

    /** And the next period collects it, through the arrears machinery that already existed. */
    public function test_the_next_period_recovers_a_carried_deduction(): void
    {
        $run = $this->openPeriod();
        $net = (float) $this->slip($run)->net_pay;
        $this->amendWith($run, 'deduction', $net + 500, 'HMO arrears');

        $next = $this->payroll()->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());
        $slip = $this->slip($next);

        $this->assertSame(500.0, (float) $slip->arrears_recovered);
        $this->assertSame([], $this->payroll()->deductionArrearsFor([$this->employee->id]));
    }

    /** On a payslip already paid, taking money off leaves the company out of pocket — and says so. */
    public function test_deducting_from_a_paid_payslip_reads_as_overpaid(): void
    {
        $run = $this->paidPeriod();
        $paid = (float) $this->slip($run)->disbursed_amount;

        $this->amendWith($run, 'deduction', 1000, 'HMO share');
        $slip = $this->slip($run);

        $this->assertSame($paid, (float) $slip->disbursed_amount, 'what left the bank does not change');
        $this->assertSame(-1000.0, $slip->amountOutstanding());
        $this->assertFalse($slip->isPartiallyPaid(), 'nothing is owed TO them — the balance runs the other way');
    }

    /**
     * A deduction moves nothing a later run's annualisation reads, so flagging one would train HR to
     * ignore the mark. Taxable pay and the tax withheld are exactly what it leaves alone.
     */
    public function test_a_missed_deduction_does_not_stale_a_later_year_end_run(): void
    {
        $run = $this->paidPeriod();
        $thirteenth = $this->thirteenthMonthRunFor(2026);

        $this->amendWith($run, 'deduction', 1000, 'HMO share');

        $this->assertFalse($thirteenth->fresh()->isTaxStale());
    }

    public function test_re_running_the_period_keeps_a_missed_deduction_once(): void
    {
        $run = $this->openPeriod();
        $this->amendWith($run, 'deduction', 1000, 'HMO share');
        $net = (float) $this->slip($run)->net_pay;

        $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $slip = $this->slip($run);

        $this->assertSame($net, (float) $slip->net_pay);
        $this->assertSame(1000.0, (float) $slip->other_deductions);
        $this->assertSame(
            1000.0,
            round(collect($slip->other_deductions_breakdown ?? [])->sum('amount'), 2),
            'a recompute must re-apply the deduction, not stack a second copy of it',
        );
    }

    // --- pay that should never have been paid --------------------------------------------------

    /**
     * REVERSING PAY IS THE OTHER NEGATIVE, and it is the opposite of a deduction on the tax: the
     * money was taxed as compensation, so unwinding it must reduce taxable pay and give back what
     * was withheld. Keeping the tax would leave the employee taxed on income they never had.
     */
    public function test_reversing_pay_reduces_taxable_pay_and_refunds_the_tax(): void
    {
        $this->seedYearToDateTaxable(600000);
        $run = $this->openPeriod();
        $before = $this->slip($run);

        $this->amendWith($run, 'pay_reversal', 5000, 'Transport allowance paid twice');
        $after = $this->slip($run);

        $refund = round((float) $before->withholding_tax - (float) $after->withholding_tax, 2);

        $this->assertSame(-5000.0, round((float) $after->adjustment_pay, 2));
        $this->assertSame(round((float) $before->taxable_pay - 5000, 2), (float) $after->taxable_pay);
        $this->assertGreaterThan(0, $refund, 'the tax withheld on pay being taken back comes back with it');
        $this->assertSame(
            round((float) $before->net_pay - 5000 + $refund, 2),
            (float) $after->net_pay,
            'net falls by the pay less the tax that is no longer due on it',
        );
    }

    public function test_the_ledger_records_a_reversal_as_negative_pay(): void
    {
        $this->seedYearToDateTaxable(600000);
        $run = $this->openPeriod();

        $this->amendWith($run, 'pay_reversal', 5000);
        $amendment = PayslipAmendment::where('payslip_id', $this->slip($run)->id)->firstOrFail();

        $this->assertSame('pay_reversal', $amendment->kind);
        $this->assertSame(-5000.0, (float) $amendment->amount);
        $this->assertLessThan(0, (float) $amendment->tax, 'a refund is a negative withholding');
        $this->assertFalse($amendment->isDeduction());
    }

    /**
     * Pay that is not on this period cannot be reversed from it. Refused rather than clamped: a
     * silent partial reversal would report the whole amount as recovered.
     */
    public function test_reversing_more_than_the_payslip_pays_is_refused(): void
    {
        $run = $this->openPeriod();
        $net = (float) $this->slip($run)->net_pay;

        try {
            $this->amendWith($run, 'pay_reversal', $net + 1000);
            $this->fail('a reversal larger than the payslip must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('more than', $e->getMessage());
        }

        $this->assertSame($net, (float) $this->slip($run)->net_pay, 'and nothing is applied');
    }

    /** It moves the year's taxable pay, so a later run settled against it IS stale. */
    public function test_reversing_pay_stales_a_later_year_end_run(): void
    {
        $run = $this->paidPeriod();
        $thirteenth = $this->thirteenthMonthRunFor(2026);

        $this->amendWith($run, 'pay_reversal', 1000);

        $this->assertTrue($thirteenth->fresh()->isTaxStale());
    }

    // --- the controls ------------------------------------------------------------------------

    public function test_a_locked_run_cannot_be_amended(): void
    {
        $run = $this->openPeriod();
        $run->update(['locked_at' => now(), 'locked_by' => $this->hrUser()->id]);

        $this->expectException(ValidationException::class);
        $this->amend($run->fresh());
    }

    /** Nothing to amend is refused, not silently skipped — the money would go nowhere. */
    public function test_an_employee_with_no_payslip_in_the_period_is_refused(): void
    {
        $run = $this->openPeriod();

        $other = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => Department::first()->id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2026-09-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        try {
            $this->payroll()->amendRun(
                $run,
                'Back pay for a period they were never in',
                [['employee_id' => $other->id, 'amount' => 1000]],
                null,
                $this->hrUser(),
            );
            $this->fail('a correction with no payslip behind it must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no payslip', $e->getMessage());
        }
    }

    public function test_a_reason_is_required(): void
    {
        $run = $this->openPeriod();

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
            'lines' => [['employee_id' => $this->employee->id, 'amount' => 500]],
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /**
     * A SIGN IS NOT HOW YOU DEDUCT. The direction belongs to `kind`, so a negative line in a batch
     * that pays would be a batch that both pays and takes — with one reason to explain both. Refused
     * at the edge, which is also what makes "the amounts are magnitudes" true rather than hoped for.
     */
    public function test_a_negative_line_amount_is_rejected(): void
    {
        $run = $this->openPeriod();

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
            'reason' => 'Recovering an overpayment from August',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => -500]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.amount');
    }

    public function test_an_unknown_kind_is_rejected(): void
    {
        $run = $this->openPeriod();

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
            'reason' => 'Recovering an overpayment from August',
            'kind' => 'clawback',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => 500]],
        ])->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    /** Omitting the kind means extra pay — what every amendment was before deductions existed. */
    public function test_a_request_without_a_kind_still_pays(): void
    {
        $run = $this->openPeriod();

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
            'reason' => 'Back pay for 1–15 August after a back-dated raise',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => 500]],
        ])->assertOk();

        $this->assertSame(500.0, (float) $this->slip($run)->adjustment_pay);
    }

    public function test_the_endpoint_deducts_when_asked_to(): void
    {
        $run = $this->openPeriod();
        $net = (float) $this->slip($run)->net_pay;

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
            'reason' => 'HMO share was not deducted for 1–15 August',
            'kind' => 'deduction',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => 750, 'note' => 'HMO share']],
        ])->assertOk()->assertJsonPath('data.id', $run->id);

        $this->assertSame(round($net - 750, 2), (float) $this->slip($run)->net_pay);
    }

    /**
     * ONE CALL, EVERY EMPLOYEE IN THE PERIOD — the shape the "everyone in this period" button posts.
     *
     * Worth its own test because a batch correction is the case where a mistake is multiplied: the
     * lines have to be applied per payslip, each with its own ledger row, rather than summed onto
     * whichever payslip the loop happened to hold.
     */
    public function test_one_call_can_correct_every_employee_in_the_period(): void
    {
        $second = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => Department::first()->id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        // Present all month, like the first: a payslip with no pay behind it could not take the
        // deduction at all, and this test is about the batch reaching both payslips.
        $tz = config('app.timezone_local');
        for ($day = Carbon::parse('2026-08-01', $tz); $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $second->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(8, 0)->utc(),
                'time_out' => $day->copy()->setTime(17, 0)->utc(),
                'status' => 'present',
            ]);
        }

        $run = $this->openPeriod();

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
            'reason' => 'HMO share was not deducted for 1–15 August',
            'kind' => 'deduction',
            'lines' => [
                ['employee_id' => $this->employee->id, 'amount' => 500, 'note' => 'HMO share'],
                ['employee_id' => $second->id, 'amount' => 500, 'note' => 'HMO share'],
            ],
        ])->assertOk();

        $this->assertSame(2, PayslipAmendment::count());

        foreach (Payslip::where('payroll_run_id', $run->id)->get() as $slip) {
            $this->assertSame(500.0, (float) $slip->other_deductions, 'each payslip takes its own line');
        }
    }

    public function test_the_endpoint_amends_the_period_and_returns_it(): void
    {
        $run = $this->openPeriod();

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
            'reason' => 'Back pay for 1–15 August after a back-dated raise',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => 2500, 'note' => 'Raise back pay']],
        ])->assertOk()->assertJsonPath('data.id', $run->id);

        $this->assertSame(2500.0, (float) $this->slip($run)->adjustment_pay);
    }

    // --- what the screens and the document are told -------------------------------------------

    /**
     * The route that raised a second payslip is GONE, not deprecated.
     *
     * Leaving it reachable would mean anything still pointed at it kept producing the exact
     * documents this feature exists to stop — and it would do so silently, since it returned a
     * perfectly successful run.
     */
    public function test_the_retired_adjustment_route_no_longer_exists(): void
    {
        $this->hrPost('/api/v1/hr/payroll/adjustment', [
            'cycle_start' => '2026-08-20', 'cycle_end' => '2026-08-20',
            'reason' => 'Back pay for 1–15 August',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => 500]],
        ])->assertNotFound();
    }

    /**
     * The run's breakdown carries the employee and both money figures.
     *
     * `employee_id` is what a correction is addressed to: with only a display name on the row, the
     * form offering to correct this period had to fetch the whole directory and match on text.
     * `disbursed`/`outstanding` are what make an amended row readable — the net alone says the
     * payslip is worth more than was paid without saying that anything is owed.
     */
    public function test_the_run_breakdown_names_the_employee_and_states_the_balance(): void
    {
        $run = $this->paidPeriod();
        $paid = (float) $this->slip($run)->disbursed_amount;
        $this->amend($run, 3000);

        $row = $this->actingAsHr()
            ->getJson("/api/v1/hr/payroll/runs/{$run->id}")
            ->assertOk()
            ->json('data.breakdown.0');

        $this->assertSame($this->employee->id, $row['employee_id']);
        $this->assertSame($paid, (float) $row['disbursed']);
        $this->assertSame(round((float) $row['net'] - $paid, 2), round((float) $row['outstanding'], 2));
        $this->assertGreaterThan(0, (float) $row['outstanding']);
    }

    /** The payslip response carries what was transferred, what is owed, and the ledger behind it. */
    public function test_the_payslip_response_carries_the_correction_ledger(): void
    {
        $run = $this->paidPeriod();
        $this->amend($run, 3000);
        $slip = $this->slip($run);

        $data = $this->actingAsHr()
            ->getJson("/api/v1/hr/payslips/{$slip->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame((float) $slip->disbursed_amount, (float) $data['disbursed_amount']);
        $this->assertNotNull($data['disbursed_at']);
        $this->assertGreaterThan(0, (float) $data['amount_outstanding']);
        $this->assertCount(1, $data['amendments']);
        $this->assertSame(3000.0, (float) $data['amendments'][0]['amount']);
        $this->assertSame('2026-08-20', $data['amendments'][0]['issued_on']);
        $this->assertStringContainsString('back-dated raise', $data['amendments'][0]['reason']);
    }

    /**
     * THE PDF SAYS SO TOO, which is the copy the employee keeps.
     *
     * A document stating a net larger than the amount in their account, with nothing accounting for
     * the difference, is the one error a payslip cannot make — and the PDF is the surface where
     * nobody can click through for the explanation.
     */
    public function test_the_pdf_states_what_was_transferred_and_lists_the_corrections(): void
    {
        $run = $this->paidPeriod();
        $this->amend($run, 3000);

        $html = view(
            'hr::payslip',
            $this->payroll()->payslipViewData($this->slip($run)),
        )->render();

        $this->assertStringContainsString('Still to be paid', $html);
        $this->assertStringContainsString('was paid out on', $html);
        $this->assertStringContainsString('Corrections to this payslip (1)', $html);
        $this->assertStringContainsString('Back pay for the 1 Aug raise', $html);
    }

    /** An untouched payslip says none of it — the net IS what was paid, and repeating it is noise. */
    public function test_an_unamended_payslip_says_nothing_about_a_balance(): void
    {
        $run = $this->paidPeriod();

        $html = view(
            'hr::payslip',
            $this->payroll()->payslipViewData($this->slip($run)),
        )->render();

        $this->assertStringNotContainsString('Still to be paid', $html);
        $this->assertStringNotContainsString('Corrections to this payslip', $html);
    }

    // --- the downstream tax basis ------------------------------------------------------------

    /**
     * A 13th month's withholding is computed on the year's earlier payslips, so amending one of them
     * leaves it settled against figures that no longer exist. December re-reads the year live, which
     * is exactly why this is invisible until it matters.
     */
    public function test_a_later_year_end_run_is_flagged_for_recompute(): void
    {
        $run = $this->paidPeriod();
        $thirteenth = $this->thirteenthMonthRunFor(2026);

        $this->amend($run);

        $thirteenth->refresh();
        $this->assertTrue($thirteenth->isTaxStale());
        $this->assertStringContainsString('amended', $thirteenth->tax_stale_reason);
    }

    /** An ordinary cutoff annualises its OWN pay, so it is untouched by an earlier correction. */
    public function test_an_ordinary_later_period_is_not_flagged(): void
    {
        $run = $this->paidPeriod();
        $next = $this->payroll()->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());

        $this->amend($run);

        $this->assertFalse($next->fresh()->isTaxStale());
    }

    /** An EARLIER run cannot have been computed from a later one, so it is never flagged. */
    public function test_an_earlier_run_is_not_flagged(): void
    {
        $july = $this->payroll()->run('2026-07-16', '2026-07-31', 'semi_monthly', $this->hrUser());
        $run = $this->paidPeriod();

        $this->amend($run);

        $this->assertFalse($july->fresh()->isTaxStale());
    }

    /** Recomputing is the only thing that resolves the mark — and it must actually clear it. */
    public function test_recomputing_a_flagged_run_clears_the_mark(): void
    {
        $run = $this->openPeriod();
        $run->update(['tax_stale_at' => now(), 'tax_stale_reason' => 'An earlier period was amended.']);

        $recomputed = $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertFalse($recomputed->isTaxStale());
        $this->assertNull($recomputed->fresh()->tax_stale_reason);
    }

    /**
     * A 13th-month run holding a payslip for this employee, written directly.
     *
     * The 13th-month ENGINE is tested by its own suite; what matters here is only that a run of that
     * type exists after the amended period, since that is what the staleness rule keys on.
     */
    private function thirteenthMonthRunFor(int $year): PayrollRun
    {
        $run = PayrollRun::create([
            'cycle_start' => "{$year}-12-01",
            'cycle_end' => "{$year}-12-31",
            'frequency' => 'thirteenth_month',
            'type' => 'thirteenth_month',
            'status' => 'completed',
            'run_at' => now(),
            'run_by' => $this->hrUser()->id,
        ]);

        Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'base_salary' => 0,
            'thirteenth_month' => 40000,
            'gross' => 40000,
            'withholding_tax' => 0,
            'total_deductions' => 0,
            'net_pay' => 40000,
            'status' => 'computed',
        ]);

        return $run;
    }

    /**
     * A settled cutoff earlier in the year carrying `$taxable` of taxable pay.
     *
     * Written directly rather than run, because what the marginal-tax rule reads is the YEAR's
     * taxable pay from its completed regular runs — and eleven real cutoffs would take a minute to
     * compute to establish a single figure.
     */
    private function seedYearToDateTaxable(float $taxable): void
    {
        $run = PayrollRun::create([
            'cycle_start' => '2026-01-01',
            'cycle_end' => '2026-01-15',
            'frequency' => 'semi_monthly',
            'type' => 'regular',
            'status' => 'completed',
            'run_at' => now(),
            'run_by' => $this->hrUser()->id,
        ]);

        Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'base_salary' => 20000,
            'gross' => 20000,
            'taxable_pay' => $taxable,
            'withholding_tax' => 0,
            'total_deductions' => 0,
            'net_pay' => 20000,
            'status' => 'disbursed',
            'disbursed_amount' => 20000,
            'disbursed_at' => now(),
        ]);
    }
}
