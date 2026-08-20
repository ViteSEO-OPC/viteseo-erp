<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** (2026-07-28 audit) — computing payroll is not paying it. */
class ReimbursementSchedulingTest extends HrTestCase
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
    }

    private function claim(string $status = 'approved', string $expenseDate = '2026-03-05'): Reimbursement
    {
        return Reimbursement::create([
            'employee_id' => $this->employee->id,
            'category' => 'transport',
            'amount' => 1500,
            'expense_date' => $expenseDate,
            'description' => 'Client visit',
            'status' => $status,
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

    /** THE BUG: computing used to mark it paid. */
    public function test_running_payroll_schedules_the_claim_rather_than_paying_it(): void
    {
        $claim = $this->claim();
        $run = $this->runPayroll();

        $this->assertSame('scheduled', $claim->fresh()->status);
        $this->assertSame($run->id, $claim->fresh()->payroll_run_id);
        // It still reaches the payslip — only the status wording changed.
        $this->assertSame(1500.0, (float) $this->slip()->reimbursements);
    }

    /** Disbursing the payslip is what actually pays it. */
    public function test_marking_the_payslip_disbursed_pays_the_claim(): void
    {
        $claim = $this->claim();
        $this->runPayroll();

        app(PayrollService::class)->markDone($this->slip());

        $this->assertSame('paid', $claim->fresh()->status);
    }

    /** A re-run keeps it scheduled — not re-scheduled, not double-counted. */
    public function test_a_re_run_leaves_a_scheduled_claim_alone(): void
    {
        $claim = $this->claim();
        $this->runPayroll();
        $this->runPayroll();

        $this->assertSame('scheduled', $claim->fresh()->status);
        $this->assertSame(1500.0, (float) $this->slip()->reimbursements);
    }

    /** THE STRANDING CASE: dropped from the run → released back to the approved pool. */
    public function test_a_dropped_employee_releases_the_claim(): void
    {
        $claim = $this->claim();
        $this->runPayroll();
        $this->assertSame('scheduled', $claim->fresh()->status);

        // Move them out of this pay group so the next run no longer covers them.
        $this->employee->update(['pay_frequency' => 'monthly']);
        $this->runPayroll();

        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->payroll_run_id);
    }

    /** Money already out is history — a paid claim is never re-touched. */
    public function test_a_paid_claim_is_left_alone_by_a_re_run(): void
    {
        $claim = $this->claim();
        $this->runPayroll();
        app(PayrollService::class)->markDone($this->slip());

        $this->runPayroll();

        $this->assertSame('paid', $claim->fresh()->status);
    }

    /** HR can still settle a scheduled claim by hand (e.g. paid in cash). */
    public function test_hr_can_mark_a_scheduled_claim_paid_manually(): void
    {
        $claim = $this->claim();
        $this->runPayroll();

        app(\App\Modules\Hr\Services\ReimbursementService::class)
            ->markPaid($claim->fresh(), User::factory()->create());

        $this->assertSame('paid', $claim->fresh()->status);
    }

    /** A pending (unapproved) claim is never folded in. */
    public function test_a_pending_claim_is_not_scheduled(): void
    {
        $claim = $this->claim('pending');
        $this->runPayroll();

        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertSame(0.0, (float) $this->slip()->reimbursements);
    }

    // ---: claims approved after their own cycle has closed ----------------------

    /**
     * THE BUG. A run folded in only claims dated INSIDE its own cycle, and no later run looked
     * backwards. A January expense approved in March could therefore only be paid by re-running
     * January — which is refused once that run is locked or its payslip disbursed. The claim sat
     * `approved` for ever, and marking it paid by hand outside payroll records no payment at all.
     */
    public function test_a_claim_from_an_earlier_closed_cycle_is_folded_into_the_current_run(): void
    {
        $old = $this->claim('approved', '2026-01-20');

        $this->runPayroll();

        $this->assertSame('scheduled', $old->fresh()->status, 'the backlogged claim was never picked up');
        $this->assertSame(1500.0, (float) $this->slip()->reimbursements);
    }

    /** A claim already scheduled onto ANOTHER run must never be stolen by this one. */
    public function test_a_claim_scheduled_on_another_run_is_left_alone(): void
    {
        $other = app(PayrollService::class)->run(
            '2026-02-01', '2026-02-15', 'semi_monthly', User::factory()->create(),
        );
        $claim = $this->claim('approved', '2026-01-20');
        $claim->update(['status' => 'scheduled', 'payroll_run_id' => $other->id]);

        $this->runPayroll();

        $this->assertSame($other->id, $claim->fresh()->payroll_run_id, 'the claim was stolen from its run');
        $this->assertSame(0.0, (float) $this->slip()->reimbursements);
    }

    /** A future-dated expense is not payable yet — the window is `<= cycleEnd`, not unbounded. */
    public function test_a_claim_dated_after_the_cycle_is_not_folded_in(): void
    {
        $future = $this->claim('approved', '2026-04-10');

        $this->runPayroll();

        $this->assertSame('approved', $future->fresh()->status);
        $this->assertSame(0.0, (float) $this->slip()->reimbursements);
    }
}
