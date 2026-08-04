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
use Illuminate\Validation\ValidationException;

/**
 * FIN-010 — off-cycle adjustment runs, the correction path the whole audit depends on.
 *
 * There were two write paths into pay: an aligned regular period and the December 13th-month run.
 * Every correction found by FIN-007 (back-dated raise), FIN-008 (attendance fix), FIN-009 (late
 * approval) and FIN-023 (late reimbursement) produced a difference with nowhere to be paid — the
 * only route was unlocking and re-running a closed period, which rewrites history and is refused
 * once payslips are disbursed.
 *
 * The two tests that matter most here are the last two: an adjustment must not disturb the regular
 * run for the same dates (FIN-001's guard is what makes that possible), and it must not freeze
 * attendance over a window it does not price.
 */
class AdjustmentRunTest extends HrTestCase
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
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** @param array<int, array<string, mixed>>|null $lines */
    private function adjust(?array $lines = null, string $start = '2026-04-01', string $end = '2026-04-01'): PayrollRun
    {
        return app(PayrollService::class)->runAdjustment(
            $start,
            $end,
            'Back pay for the 1–15 March cutoff after a back-dated raise',
            $lines ?? [['employee_id' => $this->employee->id, 'amount' => 5000, 'note' => 'March back pay']],
            User::factory()->create(),
        );
    }

    private function slip(PayrollRun $run): Payslip
    {
        return Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();
    }

    // --- it pays --------------------------------------------------------------------------

    public function test_an_adjustment_pays_a_stated_amount_with_a_reason(): void
    {
        $run = $this->adjust();

        $this->assertSame('adjustment', $run->type);
        $this->assertNotEmpty($run->reason);
        $this->assertSame(1, $run->employee_count);

        $slip = $this->slip($run);
        $this->assertSame(5000.0, (float) $slip->adjustment_pay);
        $this->assertSame(5000.0, (float) $slip->gross);
        $this->assertSame('March back pay', $slip->adjustment_note);
    }

    /** It corrects; it does not recompute. No statutory contributions are taken again. */
    public function test_an_adjustment_takes_no_statutory_contributions(): void
    {
        $slip = $this->slip($this->adjust());

        $this->assertSame(0.0, (float) $slip->sss);
        $this->assertSame(0.0, (float) $slip->philhealth);
        $this->assertSame(0.0, (float) $slip->pagibig);
        $this->assertSame(0.0, (float) $slip->base_salary, 'back pay is not basic salary for this window');
    }

    /** Back pay must not inflate the 13th-month basis, which is measured on base_salary. */
    public function test_an_adjustment_does_not_count_toward_the_thirteenth_month_basis(): void
    {
        $this->adjust();

        $ytd = app(PayrollService::class)->ytd($this->employee, 2026);

        $this->assertSame(0.0, $ytd['thirteenth_month']);
    }

    // --- it is re-runnable and safe --------------------------------------------------------

    public function test_recomputing_a_batch_replaces_its_lines(): void
    {
        $this->adjust();
        $run = $this->adjust([['employee_id' => $this->employee->id, 'amount' => 7000]]);

        $this->assertSame(7000.0, (float) $this->slip($run)->adjustment_pay);
        $this->assertSame(1, Payslip::where('payroll_run_id', $run->id)->count());
    }

    public function test_a_disbursed_adjustment_payslip_is_never_rewritten(): void
    {
        $run = $this->adjust();
        app(PayrollService::class)->markDone($this->slip($run));

        $this->adjust([['employee_id' => $this->employee->id, 'amount' => 9999]]);

        $this->assertSame(5000.0, (float) $this->slip($run)->adjustment_pay);
        $this->assertSame('disbursed', $this->slip($run)->status);
    }

    public function test_an_employee_dropped_from_the_batch_leaves_no_orphan_payslip(): void
    {
        $other = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => Department::first()->id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $run = $this->adjust([
            ['employee_id' => $this->employee->id, 'amount' => 5000],
            ['employee_id' => $other->id, 'amount' => 3000],
        ]);
        $this->assertSame(2, Payslip::where('payroll_run_id', $run->id)->count());

        $this->adjust([['employee_id' => $this->employee->id, 'amount' => 5000]]);

        $this->assertSame(0, Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $other->id)->count());
    }

    // --- it does not disturb the regular payroll ------------------------------------------

    /**
     * THE INTEROPERATION THAT MATTERS. An adjustment over dates a regular run also covers must
     * neither block it nor be blocked by it. This only works because FIN-001 made the double-pay
     * guard type-aware; before that, any second run over the same dates skipped everyone.
     */
    public function test_an_adjustment_does_not_stop_the_regular_run_for_the_same_dates(): void
    {
        $this->adjust(start: '2026-03-01', end: '2026-03-15');

        $regular = app(PayrollService::class)->run(
            '2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create(),
        );

        $this->assertSame(1, $regular->employee_count, 'the regular run skipped everyone');
        $this->assertSame('regular', $regular->type);
    }

    /** An adjustment prices no attendance, so it must not lock the days its window spans. */
    public function test_a_disbursed_adjustment_does_not_freeze_attendance(): void
    {
        $run = $this->adjust(start: '2026-03-01', end: '2026-03-15');
        app(PayrollService::class)->markDone($this->slip($run));

        $frozen = app(\App\Modules\Hr\Services\PayrollFreezeService::class)
            ->runCovering($this->employee->id, '2026-03-05');

        $this->assertNull($frozen, 'an adjustment run froze attendance it does not price');
    }

    // --- the controls ----------------------------------------------------------------------

    public function test_a_reason_is_required(): void
    {
        $this->hrPost('/api/v1/hr/payroll/adjustment', [
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-01',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => 500]],
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /** A clawback needs its own consent rules under Art. 113 — it is not this feature. */
    public function test_a_negative_adjustment_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/payroll/adjustment', [
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-01',
            'reason' => 'Recovering an overpayment from March',
            'lines' => [['employee_id' => $this->employee->id, 'amount' => -500]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.amount');
    }

    /** The window is free-form on purpose — that constraint is what blocked corrections. */
    public function test_the_window_need_not_be_an_aligned_pay_period(): void
    {
        $run = $this->adjust(start: '2026-04-08', end: '2026-04-08');

        $this->assertSame('completed', $run->status);
    }

    public function test_a_locked_adjustment_run_cannot_be_recomputed(): void
    {
        $run = $this->adjust();
        $run->update(['locked_at' => now(), 'locked_by' => User::factory()->create()->id]);

        $this->expectException(ValidationException::class);
        $this->adjust([['employee_id' => $this->employee->id, 'amount' => 1234]]);
    }
}
