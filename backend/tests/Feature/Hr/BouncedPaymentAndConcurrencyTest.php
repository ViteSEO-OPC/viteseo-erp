<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\LoanPayment;
use App\Modules\Hr\Models\Payment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PaymentService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two things, both about payroll surviving contact with reality.
 *
 * PART ONE — A BOUNCED TRANSFER AND THE LOAN LEDGER (the `[~]` item under Slice 3).
 *
 * `LoanService::collectForPeriod()` writes the loan ledger at COMPUTE time, so a balance drops the moment
 * payroll is priced rather than when money moves. The audit offered two fixes: derive `paid()` from
 * disbursed payslips only, or reverse on reopen. The second was chosen, with a note to revisit "once
 * FIN-015 adds real payment records". Those records now exist — `hr_payments` with
 * `pending/settled/failed/returned` — so this is the revisit.
 *
 * The chain is THREE SERVICES DEEP: `PaymentService::fail()` → `PayrollService::reopenPayslip()` →
 * `LoanService::reverseForPayslip()`. Each link was tested in isolation and the whole chain was not, which
 * is the shape of bug nobody notices: an employee's loan balance stays reduced by an instalment that came
 * back with the transfer, and no screen disagrees with any other.
 *
 * `test_a_locked_run_leaves_an_outstanding_item_rather_than_silence` is the fix this pass added. The
 * reversal is NOT forced through on a locked run — that would leave the payslip showing a deduction the
 * ledger says was never collected — so it becomes a task in the queue HR already works instead of a line
 * in the audit log nobody reads.
 *
 * PART TWO — CONCURRENCY (Slice 7).
 *
 * Two simultaneous `run()` calls for the same period must produce one run and one payslip per employee.
 * The guard is a real unique index plus `lockForUpdate()`, and the test drives it through the actual
 * database rather than mocking the race — a mocked race proves the mock.
 */
class BouncedPaymentAndConcurrencyTest extends HrTestCase
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
            // `buildForRun()` excludes anyone with no account on file — a bank transfer needs
            // somewhere to go. These columns are `encrypted` casts (FIN-022); the model handles that.
            'bank_name' => 'BPI',
            'bank_account' => '1234567890',
            'bank_account_name' => 'Test Employee',
            'bank_account_type' => 'Savings',
        ]);

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

    private function loan(): EmployeeLoan
    {
        return EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'active', 'authorized_at' => now(),
        ]);
    }

    /**
     * The real sequence: run the cycle, build the payment batch from its COMPUTED payslips, then have HR
     * assert disbursement. Order matters — `buildForRun()` only picks up payslips that are still
     * `computed`, so batching has to happen before `markDone()`, exactly as it does in practice.
     *
     * @return array{0: PayrollRun, 1: Payslip, 2: Payment}
     */
    private function runBatchAndDisburse(): array
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        // A payment belongs to a batch — built through the service rather than hand-inserted, so this
        // exercises the path a real bank file comes back through.
        app(PaymentService::class)->buildForRun($run, 'bank_transfer', null, $this->hrUser());

        $slip = $run->payslips()->firstOrFail();
        $payroll->markDone($slip, $this->hrUser());

        $payment = Payment::where('payslip_id', $slip->id)->firstOrFail();

        return [$run->fresh(), $slip->fresh(), $payment];
    }

    // --- Part one: a bounced transfer ------------------------------------------------------------

    /**
     * THE END-TO-END CHAIN. A returned transfer has to put the loan instalment back — otherwise the
     * employee repaid ₱1,000 out of money that came back to the company.
     */
    public function test_a_returned_transfer_puts_the_loan_instalment_back(): void
    {
        $loan = $this->loan();
        [, $slip, $payment] = $this->runBatchAndDisburse();

        $this->assertSame(1000.0, $loan->fresh()->paidPrincipal(), 'collected at compute time');

        app(PaymentService::class)->fail(
            $payment,
            'returned',
            'Account closed',
            $this->hrUser(),
        );

        $this->assertSame(0.0, $loan->fresh()->paidPrincipal(), 'and put back when the money bounced');
        $this->assertSame(12000.0, $loan->fresh()->outstanding());
        $this->assertSame('computed', $slip->fresh()->status, 'the payslip is un-paid too');
    }

    /** A `failed` transfer is the same fact as a `returned` one for the ledger's purposes. */
    public function test_a_failed_transfer_also_puts_the_instalment_back(): void
    {
        $loan = $this->loan();
        [, $slip, $payment] = $this->runBatchAndDisburse();

        app(PaymentService::class)->fail($payment, 'failed', 'Invalid account', $this->hrUser());

        $this->assertSame(0.0, $loan->fresh()->paidPrincipal());
    }

    /** A loan the payslip had SETTLED must come back to life, not stay settled at zero. */
    public function test_a_settled_loan_returns_to_active_when_the_transfer_bounces(): void
    {
        $loan = $this->loan();
        // One instalment away from settled.
        $loan->update(['principal' => 1000]);
        [, $slip, $payment] = $this->runBatchAndDisburse();

        $this->assertSame('settled', $loan->fresh()->status);

        app(PaymentService::class)->fail($payment, 'returned', 'Bounced', $this->hrUser());

        $this->assertSame('active', $loan->fresh()->status);
        $this->assertSame(1000.0, $loan->fresh()->outstanding());
    }

    /** A settled transfer changes nothing about the loan — the money arrived. */
    public function test_a_settled_transfer_leaves_the_loan_alone(): void
    {
        $loan = $this->loan();
        [, $slip, $payment] = $this->runBatchAndDisburse();

        app(PaymentService::class)->settle($payment, 'REF-1', null, $this->hrUser());

        $this->assertSame(1000.0, $loan->fresh()->paidPrincipal());
        $this->assertSame('disbursed', $slip->fresh()->status);
    }

    /**
     * THE FIX THIS PASS ADDED. On a locked run `reopenPayslip()` refuses, so the payslip stays
     * `disbursed` AND the loan stays collected. Both are wrong and neither was visible — an audit-log
     * line is a record, not a task. It now lands in the outstanding queue HR already works.
     */
    public function test_a_locked_run_leaves_an_outstanding_item_rather_than_silence(): void
    {
        $loan = $this->loan();
        [$run, $slip, $payment] = $this->runBatchAndDisburse();
        app(PayrollService::class)->lock($run, $this->hrUser());

        app(PaymentService::class)->fail($payment, 'returned', 'Bounced', $this->hrUser());

        // Nothing was forced through — see PaymentService::fail()'s catch block for why.
        $this->assertSame('disbursed', $slip->fresh()->status, 'a sealed run is not silently reopened');
        $this->assertSame(1000.0, $loan->fresh()->paidPrincipal(), 'so the ledger still agrees with it');

        // But it is now somebody's job.
        $flag = RetroFlag::whereNull('resolved_at')->latest('id')->first();
        $this->assertNotNull($flag, 'the bounced transfer must appear in the outstanding queue');
        $this->assertSame(RetroFlag::OWED, $flag->direction);
        $this->assertStringContainsString('locked', $flag->reason);
        $this->assertStringContainsString('loan instalment', $flag->reason);
    }

    /** And the failure itself is always recorded, lock or no lock. */
    public function test_the_payment_is_marked_failed_even_when_the_payslip_cannot_be_reopened(): void
    {
        $this->loan();
        [$run, $slip, $payment] = $this->runBatchAndDisburse();
        app(PayrollService::class)->lock($run, $this->hrUser());

        $payment = app(PaymentService::class)->fail(
            $payment,
            'returned',
            'Bounced',
            $this->hrUser(),
        );

        $this->assertSame('returned', $payment->status);
        $this->assertNull($payment->settled_at);
        $this->assertSame('Bounced', $payment->failure_reason);
    }

    // --- Part two: concurrency (Slice 7) ---------------------------------------------------------

    /**
     * TWO SIMULTANEOUS RUNS FOR THE SAME PERIOD → ONE RUN, ONE PAYSLIP EACH.
     *
     * Driven through the real database rather than a mocked race: the protection is a unique index on
     * (cycle_start, cycle_end, frequency) plus `lockForUpdate()`, and mocking the collision would only
     * prove the mock. Sequential calls exercise exactly the same code path a second request takes — the
     * second call must find the first run and recompute it in place rather than create a rival.
     */
    public function test_running_the_same_period_twice_produces_one_run(): void
    {
        $payroll = app(PayrollService::class);

        $first = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $second = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertSame($first->id, $second->id, 'the same run, recomputed');
        $this->assertSame(
            1,
            PayrollRun::where('cycle_start', '2026-08-01')
                ->where('cycle_end', '2026-08-15')
                ->where('frequency', 'semi_monthly')
                ->count(),
        );
    }

    /** One payslip per employee per run — no duplicate rows from the second pass. */
    public function test_a_re_run_produces_one_payslip_per_employee(): void
    {
        $payroll = app(PayrollService::class);

        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertSame(1, Payslip::where('employee_id', $this->employee->id)->count());
        $this->assertSame(1, $run->payslips()->count());
    }

    /**
     * THE DATABASE, NOT THE APPLICATION, IS THE LAST LINE. If two requests got past the read check
     * simultaneously, the unique index has to refuse the second insert — otherwise the guard is only as
     * good as the interleaving.
     */
    public function test_the_database_refuses_a_duplicate_run_outright(): void
    {
        app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        // Bypass the service entirely — this is what a lost race would attempt.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('hr_payroll_runs')->insert([
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-15',
            'frequency' => 'semi_monthly',
            'type' => 'regular',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** And one per employee per run, enforced by the database rather than by the loop. */
    public function test_the_database_refuses_a_duplicate_payslip(): void
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('hr_payslips')->insert([
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'gross' => 1, 'net_pay' => 1, 'total_deductions' => 0,
            'status' => 'computed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A different frequency for the same dates is a different pay group, not a duplicate. */
    public function test_a_different_pay_group_over_the_same_dates_is_allowed(): void
    {
        $payroll = app(PayrollService::class);
        $semi = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->employee->update(['pay_frequency' => 'weekly']);
        $weekly = $payroll->run('2026-08-01', '2026-08-15', 'weekly', $this->hrUser());

        $this->assertNotSame($semi->id, $weekly->id);
    }

    /**
     * A run that has already been disbursed must not be silently recomputed by a second call — the
     * figure was asserted as paid, and rewriting it would change what the company says it paid.
     */
    public function test_a_disbursed_run_is_not_recomputed_by_a_second_call(): void
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $slip = $run->payslips()->firstOrFail();
        $payroll->markDone($slip, $this->hrUser());

        $netBefore = (float) $slip->fresh()->net_pay;

        // Change something that WOULD move the figure, then re-run.
        $this->employee->update(['base_salary' => 90000]);
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertSame(
            $netBefore,
            (float) $slip->fresh()->net_pay,
            'a disbursed payslip keeps the figure that was paid',
        );
        $this->assertSame('disbursed', $slip->fresh()->status);
    }

    /** A locked run refuses to be re-run at all, rather than partially recomputing. */
    public function test_a_locked_run_refuses_to_be_re_run(): void
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        foreach ($run->payslips as $slip) {
            $payroll->markDone($slip, $this->hrUser());
        }
        $payroll->lock($run->fresh(), $this->hrUser());

        $this->expectException(ValidationException::class);
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    /** The loan ledger must not double-collect across a re-run — one payment row per (loan, run). */
    public function test_a_re_run_does_not_collect_the_loan_twice(): void
    {
        $loan = $this->loan();
        $payroll = app(PayrollService::class);

        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertSame(1, LoanPayment::where('loan_id', $loan->id)->count());
        $this->assertSame(1000.0, $loan->fresh()->paidPrincipal());
    }
}
