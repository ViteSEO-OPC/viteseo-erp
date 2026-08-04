<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payment;
use App\Modules\Hr\Models\PaymentBatch;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PaymentService;
use App\Modules\Hr\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/**
 * FIN-015 — the disbursement layer.
 *
 * Payroll computed net pay and then stopped tracking it: "disbursement" was a manual status flip
 * with no payment record, no bank reference and no way to record that a transfer bounced. Bank
 * details were collected on every employee and never used.
 *
 * The audit's own validation for this finding is the first test below — batch total equals the
 * run's `total_net`, and every payslip appears exactly once. The rest guard the properties that
 * make the batch trustworthy: it cannot be raised twice for the same payslips, it does not assert
 * payment it has not observed, and the account it records is the account at the time it paid.
 */
class PaymentBatchTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    private User $hr;

    /** @var array<int, Employee> */
    private array $employees = [];

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
        $this->hr = User::factory()->create();

        foreach ([1, 2, 3] as $n) {
            $this->employees[] = Employee::create([
                'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
                'employee_no' => "E-00{$n}", 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
                'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
                'bank_name' => 'BDO', 'bank_account' => "00123456789{$n}",
                'bank_account_name' => "Employee {$n}", 'bank_account_type' => 'savings',
            ]);
        }
    }

    private function workedEveryDay(): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);

        foreach ($this->employees as $employee) {
            for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end); $d->addDay()) {
                if ($d->isWeekend()) {
                    continue;
                }

                Attendance::create([
                    'employee_id' => $employee->id,
                    'work_date' => $d->toDateString(),
                    'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                    'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                    'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                    'source' => Attendance::SOURCE_MANUAL,
                ]);
            }
        }
    }

    private function runPayroll(): PayrollRun
    {
        $this->workedEveryDay();

        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', $this->hr,
        );
    }

    private function build(PayrollRun $run, string $method = 'bank_transfer'): array
    {
        return app(PaymentService::class)->buildForRun($run, $method, null, $this->hr);
    }

    // --- the audit's stated validation ---------------------------------------------------

    /** "Assert batch total == run.total_net and every payslip is represented exactly once." */
    public function test_the_batch_totals_the_run_and_covers_every_payslip_once(): void
    {
        $run = $this->runPayroll();
        $batch = $this->build($run)['batch'];

        $this->assertEqualsWithDelta((float) $run->total_net, (float) $batch->total_amount, 0.01);
        $this->assertSame(3, (int) $batch->payment_count);

        $payslipIds = Payment::where('payment_batch_id', $batch->id)->pluck('payslip_id');
        $this->assertCount(3, $payslipIds);
        $this->assertSame($payslipIds->count(), $payslipIds->unique()->count(), 'a payslip appears once');

        $reconciliation = app(PaymentService::class)->reconcileWithRun($batch);
        $this->assertTrue($reconciliation['balanced'], 'the batch must account for the whole run');
        $this->assertEqualsWithDelta(0.0, $reconciliation['difference'], 0.01);
    }

    // --- paying twice ---------------------------------------------------------------------

    /** The guard that matters most: a second batch must not re-pay what the first one covers. */
    public function test_a_second_batch_cannot_re_pay_the_same_payslips(): void
    {
        $run = $this->runPayroll();
        $this->build($run);

        $this->expectException(ValidationException::class);
        $this->build($run);
    }

    /** An employee already paid by hand is not paid again by the batch. */
    public function test_a_manually_disbursed_payslip_is_skipped(): void
    {
        $run = $this->runPayroll();
        $payslip = Payslip::where('payroll_run_id', $run->id)->firstOrFail();
        app(PayrollService::class)->markDone($payslip);

        $result = $this->build($run);

        $this->assertSame(1, $result['skipped']['already_disbursed']);
        $this->assertSame(2, (int) $result['batch']->payment_count);
        $this->assertDatabaseMissing('hr_payments', ['payslip_id' => $payslip->id]);
    }

    // --- what a batch does NOT claim -------------------------------------------------------

    /**
     * Building a batch must not mark anything disbursed.
     *
     * This is the whole reason the three states exist. A batch says "we intend to pay these"; only
     * the bank's confirmation says money moved. Flipping payslips here would rebuild the
     * assertion-without-evidence that FIN-052 is about, with more tables behind it.
     */
    public function test_building_a_batch_does_not_mark_any_payslip_disbursed(): void
    {
        $run = $this->runPayroll();
        $batch = $this->build($run)['batch'];

        $this->assertSame(
            0,
            Payslip::where('payroll_run_id', $run->id)->where('status', 'disbursed')->count(),
            'a batch is an intention, not a settlement',
        );
        $this->assertSame(3, Payment::where('payment_batch_id', $batch->id)->where('status', 'pending')->count());
        $this->assertNull(Payment::where('payment_batch_id', $batch->id)->first()->settled_at);
    }

    /** A run that has not finished computing cannot be paid. */
    public function test_an_incomplete_run_cannot_be_paid(): void
    {
        $run = $this->runPayroll();
        $run->update(['status' => 'processing']);

        $this->expectException(ValidationException::class);
        $this->build($run->fresh());
    }

    // --- employees with nowhere to send the money -------------------------------------------

    /**
     * Found against the demo data, not by this suite: 11 of 12 employees had no bank account and
     * the batch wrote 11 transfers with a blank account number. A bank file like that is rejected
     * outright, or — worse — part-processed.
     */
    public function test_an_employee_with_no_bank_account_is_left_out_of_a_transfer_batch(): void
    {
        $run = $this->runPayroll();
        $this->employees[0]->update(['bank_account' => null]);

        $result = $this->build($run);

        $this->assertSame(1, $result['skipped']['missing_bank_details']);
        $this->assertSame(2, (int) $result['batch']->payment_count);
        $this->assertDatabaseMissing('hr_payments', ['employee_id' => $this->employees[0]->id]);

        // And the run is visibly NOT fully covered, so this cannot pass as a complete payout.
        $reconciliation = app(PaymentService::class)->reconcileWithRun($result['batch']);
        $this->assertFalse($reconciliation['balanced']);
        $this->assertSame(1, $reconciliation['unpaid_payslips']);
    }

    /** Cash needs no account, so the same employee is payable by a cash batch. */
    public function test_a_cash_batch_does_not_require_bank_details(): void
    {
        $run = $this->runPayroll();
        $this->employees[0]->update(['bank_account' => null]);

        $result = $this->build($run, 'cash');

        $this->assertSame(0, $result['skipped']['missing_bank_details']);
        $this->assertSame(3, (int) $result['batch']->payment_count);
    }

    /** With nowhere at all to send it, say so in terms HR can act on. */
    public function test_a_run_with_no_bank_details_anywhere_names_the_problem(): void
    {
        $run = $this->runPayroll();

        foreach ($this->employees as $employee) {
            $employee->update(['bank_account' => null]);
        }

        try {
            $this->build($run);
            $this->fail('a transfer batch with no accounts should be refused');
        } catch (ValidationException $e) {
            $message = $e->errors()['payroll_run_id'][0];
            $this->assertStringContainsString('bank details', $message);
            $this->assertStringContainsString('E-001', $message, 'name who is missing');
            $this->assertStringContainsString('cash or check', $message, 'offer the way forward');
        }
    }

    /**
     * The refusal must describe the ACTUAL cause when several apply.
     *
     * Caught against the dev data: two employees had bank details and were already batched, ten
     * had none, and the refusal announced "No one on this run has bank details on file" — false,
     * and it sends HR to the employee records instead of to the batch they already made.
     */
    public function test_the_refusal_names_every_reason_not_just_the_last_one(): void
    {
        $run = $this->runPayroll();
        $this->employees[2]->update(['bank_account' => null]);

        // Batches the two who can be paid, leaving one with no details.
        $this->build($run);

        try {
            $this->build($run);
            $this->fail('a second batch should be refused');
        } catch (ValidationException $e) {
            $message = $e->errors()['payroll_run_id'][0];
            $this->assertStringContainsString('2 already in a payment batch', $message);
            $this->assertStringContainsString('1 with no bank details', $message);
            $this->assertStringNotContainsString('No one on this run', $message);
        }
    }

    // --- the account snapshot ---------------------------------------------------------------

    /**
     * Where the money went must not change when the employee changes bank. The payment is evidence
     * of a transfer that already happened, and evidence that mutates is not evidence.
     */
    public function test_the_account_is_snapshotted_not_joined(): void
    {
        $run = $this->runPayroll();
        $batch = $this->build($run)['batch'];

        $employee = $this->employees[0];
        $payment = Payment::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('001234567891', $payment->bank_account);

        $employee->update(['bank_account' => '999999999999', 'bank_name' => 'BPI']);

        $payment->refresh();
        $this->assertSame('001234567891', $payment->bank_account, 'the snapshot must not follow the employee');
        $this->assertSame('BDO', $payment->bank_name);
    }

    /** FIN-022 — the account number is stored encrypted, so a database dump does not expose it. */
    public function test_the_stored_account_number_is_ciphertext(): void
    {
        $run = $this->runPayroll();
        $this->build($run);

        $raw = \DB::table('hr_payments')->value('bank_account');

        $this->assertNotSame('001234567891', $raw);
        $this->assertStringNotContainsString('001234567891', (string) $raw);
    }

    // --- the bank file ------------------------------------------------------------------------

    public function test_the_bank_file_carries_every_transfer_and_marks_the_batch_sent(): void
    {
        $run = $this->runPayroll();
        $batch = $this->build($run)['batch'];

        $csv = app(PaymentService::class)->bankFile($batch, $this->hr);

        foreach ($this->employees as $employee) {
            $this->assertStringContainsString($employee->employee_no, $csv);
        }

        // The bank needs the real account number — this is the one artifact that legitimately
        // carries it, which is why everything shown on screen is masked instead.
        $this->assertStringContainsString('001234567891', $csv);

        // The pay period is a date range a human at the bank reads. Date-cast columns interpolate
        // as "2026-03-01 00:00:00" — a midnight nobody asked about.
        $this->assertStringContainsString('2026-03-01 to 2026-03-15', $csv);
        $this->assertStringNotContainsString('00:00:00', $csv);

        $batch->refresh();
        $this->assertNotNull($batch->exported_at, 'downloading the file is the point of no return');
        $this->assertSame('exported', $batch->status);
        $this->assertTrue($batch->isSent());
    }

    /** Re-downloading must not restamp the export — the first send is the one that counts. */
    public function test_re_downloading_the_bank_file_keeps_the_original_sent_timestamp(): void
    {
        $run = $this->runPayroll();
        $batch = $this->build($run)['batch'];

        app(PaymentService::class)->bankFile($batch, $this->hr);
        $first = $batch->fresh()->exported_at;

        app(PaymentService::class)->bankFile($batch->fresh(), $this->hr);

        $this->assertEquals($first, $batch->fresh()->exported_at);
    }

    // --- the API ------------------------------------------------------------------------------

    public function test_hr_can_build_and_read_a_batch_over_the_api(): void
    {
        $run = $this->runPayroll();

        $created = $this->hrPost('/api/v1/hr/payment-batches', [
            'payroll_run_id' => $run->id,
            'method' => 'bank_transfer',
        ])
            ->assertCreated()
            ->json();

        $this->assertSame(3, $created['data']['payment_count']);
        $this->assertTrue($created['meta']['reconciliation']['balanced']);

        $shown = $this->actingAsHr()
            ->getJson("/api/v1/hr/payment-batches/{$created['data']['id']}")
            ->assertOk()
            ->json('data');

        // Masked on the way out, always — FIN-022. There is no unmask flag on this endpoint.
        $this->assertStringStartsWith('••••', $shown['payments'][0]['bank_account_masked']);
        $this->assertArrayNotHasKey('bank_account', $shown['payments'][0]);
    }

    public function test_an_employee_cannot_reach_the_payment_batches(): void
    {
        // Seeds the roles table as a side effect, which `hr_employee` needs to exist.
        $this->hrUser();

        $staff = User::factory()->create();
        $staff->assignRole('hr_employee');

        \Laravel\Sanctum\Sanctum::actingAs($staff);

        $this->getJson('/api/v1/hr/payment-batches')->assertForbidden();
    }
}
