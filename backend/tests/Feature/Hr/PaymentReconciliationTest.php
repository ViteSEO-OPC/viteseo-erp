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
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PaymentService;
use App\Modules\Hr\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

/**
 * Reconciliation — the point at which "paid" stops being an assertion (FIN-015).
 *
 * A batch says the company INTENDS to pay. Only the bank's confirmation says money moved, and the
 * expensive half is the opposite case: before this, a bounced transfer left the payslip reading
 * `disbursed` and nothing anywhere said the employee had not been paid.
 *
 * The property that matters most here is that settling and un-settling go through
 * `PayrollService::markDone()` / `reopenPayslip()` rather than writing the status column. Those
 * methods also settle the reimbursements a payslip carried and put back the loan instalments it
 * collected — a reconciliation that wrote the column directly would pay someone's expenses on
 * paper and strand the claims forever. That is asserted explicitly below.
 */
class PaymentReconciliationTest extends HrTestCase
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

        foreach ([1, 2] as $n) {
            $this->employees[] = Employee::create([
                'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
                'employee_no' => "E-00{$n}", 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
                'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
                'bank_name' => 'BDO', 'bank_account' => "00123456789{$n}",
                'bank_account_name' => "Employee {$n}", 'bank_account_type' => 'savings',
            ]);
        }
    }

    private function runPayroll(): PayrollRun
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

        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', $this->hr,
        );
    }

    private function batch(): PaymentBatch
    {
        return app(PaymentService::class)
            ->buildForRun($this->runPayroll(), 'bank_transfer', null, $this->hr)['batch'];
    }

    // --- settling -----------------------------------------------------------------------------

    /** A confirmed transfer is the only thing that turns a payslip into `disbursed`. */
    public function test_settling_a_payment_disburses_its_payslip(): void
    {
        $batch = $this->batch();
        $payment = $batch->payments()->firstOrFail();

        $this->assertSame('computed', $payment->payslip->status);

        app(PaymentService::class)->settle($payment, 'BDO-REF-99', '2026-03-20 09:00:00', $this->hr);

        $payment->refresh();
        $this->assertSame('settled', $payment->status);
        $this->assertSame('BDO-REF-99', $payment->reference);
        $this->assertNotNull($payment->settled_at);
        $this->assertSame('disbursed', $payment->payslip->fresh()->status);
    }

    /**
     * Settling must go through `markDone()`, not write the column.
     *
     * Disbursing a payslip also settles the reimbursements it carried. A reconciliation that set
     * `status` directly would leave those claims `scheduled` forever — paid on the payslip, unpaid
     * in the reimbursement ledger.
     */
    public function test_settling_also_settles_the_reimbursements_the_payslip_carried(): void
    {
        $employee = $this->employees[0];

        Reimbursement::create([
            'employee_id' => $employee->id,
            'category' => 'transport',
            'expense_date' => '2026-03-05',
            'amount' => 500,
            'description' => 'Client visit',
            'status' => 'approved',
        ]);

        $batch = $this->batch();
        $payment = $batch->payments()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame('scheduled', Reimbursement::where('employee_id', $employee->id)->value('status'));

        app(PaymentService::class)->settle($payment, 'REF-1', null, $this->hr);

        $this->assertSame(
            'paid',
            Reimbursement::where('employee_id', $employee->id)->value('status'),
            'the claim the payslip carried must be settled with it',
        );
    }

    public function test_a_payment_cannot_be_settled_twice(): void
    {
        $batch = $this->batch();
        $payment = $batch->payments()->firstOrFail();

        app(PaymentService::class)->settle($payment, 'REF-1', null, $this->hr);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PaymentService::class)->settle($payment->fresh(), 'REF-2', null, $this->hr);
    }

    // --- the half that was invisible ----------------------------------------------------------

    /**
     * A bounced transfer must un-disburse the payslip. Money that came back is money still owed,
     * and before this the payslip went on reading `disbursed` with nothing to contradict it.
     */
    public function test_a_returned_transfer_reopens_the_payslip(): void
    {
        $batch = $this->batch();
        $payment = $batch->payments()->firstOrFail();

        app(PaymentService::class)->settle($payment, 'REF-1', null, $this->hr);
        $this->assertSame('disbursed', $payment->payslip->fresh()->status);

        app(PaymentService::class)->fail($payment->fresh(), 'returned', 'Account closed', $this->hr);

        $payment->refresh();
        $this->assertSame('returned', $payment->status);
        $this->assertSame('Account closed', $payment->failure_reason);
        $this->assertNull($payment->settled_at, 'a returned transfer never cleared');
        $this->assertSame('computed', $payment->payslip->fresh()->status);
    }

    // --- batch status ---------------------------------------------------------------------

    public function test_the_batch_reports_partial_until_every_transfer_is_confirmed(): void
    {
        $batch = $this->batch();
        $payments = $batch->payments()->get();

        app(PaymentService::class)->settle($payments[0], 'REF-1', null, $this->hr);
        $this->assertSame('partial', $batch->fresh()->status);

        app(PaymentService::class)->settle($payments[1], 'REF-2', null, $this->hr);
        $this->assertSame('settled', $batch->fresh()->status);
    }

    // --- the import -----------------------------------------------------------------------

    private function upload(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('return.csv', $csv);
    }

    private function returnFile(PaymentBatch $batch, string $status = 'Paid'): string
    {
        $service = app(PaymentService::class);
        $lines = ['Payment Ref,Status,Bank Reference,Settled Date'];

        foreach ($batch->payments()->get() as $payment) {
            $lines[] = $service->paymentRef($payment) . ",{$status},BANK-{$payment->id},2026-03-20";
        }

        return implode("\n", $lines);
    }

    /** Preview writes nothing. This import disburses payslips; it should not do that unread. */
    public function test_the_import_previews_without_changing_anything(): void
    {
        $batch = $this->batch();

        $result = app(PaymentService::class)
            ->importReconciliation($batch, $this->returnFile($batch), false, $this->hr);

        $this->assertFalse($result['applied']);
        $this->assertSame(2, $result['settled']);
        $this->assertSame([], $result['unmatched']);

        $this->assertSame(2, $batch->payments()->where('status', 'pending')->count());
        $this->assertSame(0, Payslip::where('status', 'disbursed')->count());
    }

    public function test_confirming_the_import_settles_every_matched_transfer(): void
    {
        $batch = $this->batch();

        $result = app(PaymentService::class)
            ->importReconciliation($batch, $this->returnFile($batch), true, $this->hr);

        $this->assertTrue($result['applied']);
        $this->assertSame(2, $result['settled']);
        $this->assertSame(2, $batch->payments()->where('status', 'settled')->count());
        $this->assertSame(2, Payslip::where('status', 'disbursed')->count());
        $this->assertSame('settled', $batch->fresh()->status);
    }

    public function test_the_import_records_failures_from_the_bank(): void
    {
        $batch = $this->batch();

        $result = app(PaymentService::class)
            ->importReconciliation($batch, $this->returnFile($batch, 'Rejected'), true, $this->hr);

        $this->assertSame(2, $result['failed']);
        $this->assertSame(0, $result['settled']);
        $this->assertSame(2, $batch->payments()->where('status', 'failed')->count());
        $this->assertSame(0, Payslip::where('status', 'disbursed')->count());
    }

    /**
     * An unrecognised status is reported, never assumed successful. Guessing "OK" from an unknown
     * word is exactly how a bounced transfer becomes a paid payslip.
     */
    public function test_an_unrecognised_status_is_reported_not_guessed(): void
    {
        $batch = $this->batch();

        $result = app(PaymentService::class)
            ->importReconciliation($batch, $this->returnFile($batch, 'PROCESSING?'), true, $this->hr);

        $this->assertSame(0, $result['settled']);
        $this->assertSame(0, $result['failed']);
        $this->assertCount(2, $result['unmatched']);
        $this->assertStringContainsString('unrecognised status', $result['unmatched'][0]);
        $this->assertSame(2, $batch->payments()->where('status', 'pending')->count());
    }

    /** A row for someone not in this batch is reported rather than silently dropped. */
    public function test_a_row_that_matches_nothing_is_reported(): void
    {
        $batch = $this->batch();
        $csv = "Payment Ref,Status\nPB-999-999,Paid";

        $result = app(PaymentService::class)->importReconciliation($batch, $csv, true, $this->hr);

        $this->assertSame(0, $result['settled']);
        $this->assertCount(1, $result['unmatched']);
    }

    /** Employee number is accepted for banks that will not echo an arbitrary reference column. */
    public function test_the_import_can_match_on_employee_number(): void
    {
        $batch = $this->batch();
        $csv = "Employee No,Status,Bank Reference\nE-001,Success,XYZ-1";

        $result = app(PaymentService::class)->importReconciliation($batch, $csv, true, $this->hr);

        $this->assertSame(1, $result['settled']);
        $this->assertSame('XYZ-1', Payment::where('status', 'settled')->value('reference'));
    }

    /** Excel writes a BOM; without stripping it the first header never matches. */
    public function test_a_file_saved_by_excel_still_matches_its_first_column(): void
    {
        $batch = $this->batch();
        $csv = "\xEF\xBB\xBF" . "Employee No,Status\nE-001,Paid";

        $result = app(PaymentService::class)->importReconciliation($batch, $csv, true, $this->hr);

        $this->assertSame(1, $result['settled']);
    }

    // --- the API --------------------------------------------------------------------------

    public function test_hr_can_reconcile_over_the_api(): void
    {
        $batch = $this->batch();
        $csv = $this->returnFile($batch);

        $preview = $this->actingAsHr()
            ->post("/api/v1/hr/payment-batches/{$batch->id}/reconcile", ['file' => $this->upload($csv)])
            ->assertOk()
            ->json('data');

        $this->assertFalse($preview['applied']);
        $this->assertSame(0, Payslip::where('status', 'disbursed')->count());

        $applied = $this->actingAsHr()
            ->post("/api/v1/hr/payment-batches/{$batch->id}/reconcile", [
                'file' => $this->upload($csv),
                'confirm' => 1,
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($applied['applied']);
        $this->assertSame(2, Payslip::where('status', 'disbursed')->count());
    }

    public function test_hr_can_mark_one_transfer_by_hand(): void
    {
        $batch = $this->batch();
        $payment = $batch->payments()->firstOrFail();

        $this->actingAsHr()
            ->patchJson("/api/v1/hr/payments/{$payment->id}", [
                'status' => 'settled',
                'reference' => 'MANUAL-1',
            ])
            ->assertOk();

        $this->assertSame('disbursed', $payment->payslip->fresh()->status);
    }

    /** A failure needs a reason — "failed" alone tells whoever has to chase it nothing. */
    public function test_marking_a_transfer_failed_requires_a_reason(): void
    {
        $batch = $this->batch();
        $payment = $batch->payments()->firstOrFail();

        $this->actingAsHr()
            ->patchJson("/api/v1/hr/payments/{$payment->id}", ['status' => 'failed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('failure_reason');
    }

    // --- FIN-052: the report must not claim money moved -------------------------------------

    /**
     * The headline figure management reads first used to be "Net paid (YTD)" over COMPLETED RUNS.
     * A run is completed when payroll finishes computing it — whether the money left is a separate
     * fact. A computed-but-unpaid cycle therefore inflated reported cash by a full period.
     */
    public function test_the_payroll_report_separates_computed_from_disbursed(): void
    {
        $batch = $this->batch();
        $report = app(\App\Modules\Hr\Services\ReportService::class)->get('payroll');

        $labels = array_column($report['summary'], 'label');
        $this->assertContains('Net computed (YTD)', $labels);
        $this->assertContains('Net disbursed (YTD)', $labels);
        $this->assertNotContains('Net paid (YTD)', $labels, 'the ambiguous label is what caused this');

        $by = array_column($report['summary'], 'value', 'label');

        // Nothing settled yet: computed is non-zero, disbursed is flat zero.
        $this->assertNotSame($by['Net computed (YTD)'], $by['Net disbursed (YTD)']);
        // `peso()` renders whole pesos in on-screen summaries (FIN-017), so this is "₱0", not "₱0.00".
        $this->assertSame('₱0', $by['Net disbursed (YTD)']);

        // Settle everything; now the two agree and nothing is awaiting payment.
        foreach ($batch->payments()->get() as $payment) {
            app(PaymentService::class)->settle($payment, 'REF', null, $this->hr);
        }

        $after = array_column(
            app(\App\Modules\Hr\Services\ReportService::class)->get('payroll')['summary'],
            'value',
            'label',
        );

        $this->assertSame($after['Net computed (YTD)'], $after['Net disbursed (YTD)']);
        $this->assertSame('₱0', $after['Awaiting payment']);
    }
}
