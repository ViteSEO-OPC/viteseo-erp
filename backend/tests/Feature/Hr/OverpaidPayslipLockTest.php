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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** A payslip corrected DOWNWARD after the money was already transferred. */
class OverpaidPayslipLockTest extends HrTestCase
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

        $tz = config('app.timezone_local');
        for ($day = Carbon::parse('2026-08-01', $tz); $day->day <= 15; $day->addDay()) {
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

    /** Run one cutoff, pay it out in full, and hand back the payslip. */
    private function paidPayslip(): Payslip
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $slip = $run->payslips()->firstOrFail();

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payslips/{$slip->id}/mark-done")
            ->assertOk();

        return $slip->fresh();
    }

    /** Correct a line on a payslip that has already been paid. */
    private function correct(Payslip $slip, string $key, float $amount): Payslip
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payslips/{$slip->id}/line-corrections", [
                'target_key' => $key,
                'amount' => $amount,
                'reason' => 'Unpaid suspension the ledger never recorded',
            ])
            ->assertOk();

        return $slip->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function lock(Payslip $slip, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payroll/runs/{$slip->payroll_run_id}/lock", $payload);
    }

    /** A correction below what was paid leaves a NEGATIVE balance, which is the overpayment. */
    public function test_a_downward_correction_leaves_a_negative_balance(): void
    {
        $slip = $this->correct($this->paidPayslip(), 'suspended_unpaid', 5000);

        $this->assertSame(-5000.0, $slip->amountOutstanding());
        $this->assertSame('disbursed', $slip->status);

        $row = $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/payroll/runs/{$slip->payroll_run_id}")
            ->assertOk()
            ->json('data.breakdown.0');

        $this->assertSame(-5000.0, (float) $row['outstanding'], 'HR can see it on the run');
    }

    /**
     * THE BUG: closing the books over money the company never got back, in silence.
     *
     * The guard beside this one only ever asked `net_pay > disbursed_amount`, so it could not
     * see the case where the inequality points the other way.
     */
    public function test_locking_is_refused_while_an_overpayment_is_unrecovered(): void
    {
        $slip = $this->correct($this->paidPayslip(), 'suspended_unpaid', 5000);

        $this->lock($slip)
            ->assertStatus(422)
            ->assertJsonValidationErrors('acknowledge_overpayment');

        $this->assertNull(
            PayrollRun::findOrFail($slip->payroll_run_id)->locked_at,
            'the run must still be open',
        );
    }

    /** The refusal names the money, because "could not lock" is not an answer HR can act on. */
    public function test_the_refusal_states_how_much_was_overpaid(): void
    {
        $slip = $this->correct($this->paidPayslip(), 'suspended_unpaid', 5000);

        $message = $this->lock($slip)->json('errors.acknowledge_overpayment.0');

        $this->assertStringContainsString('5,000.00', $message);
        $this->assertStringContainsString('1 payslip', $message);
    }

    /**
     * A question, not a wall. Wages already paid cannot be clawed back without the employee's
     * written consent, so nothing HR does would ever clear this — the run has to be lockable.
     */
    public function test_confirming_lets_the_lock_through(): void
    {
        $slip = $this->correct($this->paidPayslip(), 'suspended_unpaid', 5000);

        $this->lock($slip, ['acknowledge_overpayment' => true])->assertOk();

        $this->assertNotNull(PayrollRun::findOrFail($slip->payroll_run_id)->locked_at);
    }

    /** REGRESSION: an ordinary fully-paid run still locks with no confirmation at all. */
    public function test_a_run_with_nothing_overpaid_locks_untouched(): void
    {
        $slip = $this->paidPayslip();

        $this->lock($slip)->assertOk();

        $this->assertNotNull(PayrollRun::findOrFail($slip->payroll_run_id)->locked_at);
    }

    /** REGRESSION: the opposite direction is still a hard block — that balance CAN be paid. */
    public function test_an_underpayment_is_still_refused_outright(): void
    {
        $slip = $this->correct($this->paidPayslip(), 'reimbursement', 5000);

        $this->assertSame(5000.0, $slip->amountOutstanding(), 'fixture check: it owes money');

        $this->lock($slip, ['acknowledge_overpayment' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locked_at');

        $this->assertNull(PayrollRun::findOrFail($slip->payroll_run_id)->locked_at);
    }
}
