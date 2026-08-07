<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ReimbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Slice 7 — what happens when something that changes a settled period arrives late.
 *
 * THE ITEM'S PREMISE IS TRUE FOR ONE OF ITS THREE SCENARIOS, and that is the finding here. It asks that a
 * back-dated raise, a back-dated attendance correction and a late-approved reimbursement "each produce a
 * correct adjustment payslip". Only the first does. The other two are handled by different mechanisms,
 * deliberately, and asserting an adjustment payslip for them would mean changing working code to match a
 * sentence rather than the other way round:
 *
 *   · a **back-dated raise** raises a retro flag, and an off-cycle adjustment run settles it (FIN-007 →
 *     FIN-009 → FIN-010). That is the journey this file walks end to end.
 *   · a **back-dated attendance correction** is REFUSED while the period is settled (FIN-008): attendance
 *     is the input payroll priced the day from, so rewriting it behind a disbursed payslip would leave the
 *     record and the payment permanently disagreeing. Unlock the run, or don't change it.
 *   · a **late-approved reimbursement** needs no adjustment at all — an approved claim is picked up by the
 *     next run, which is what the feature spec says should happen ("approved claims roll into the next
 *     payroll run").
 *
 * So each scenario is tested against the behaviour the system actually implements, and the mismatch with
 * the checklist wording is recorded rather than papered over.
 */
class RetroAndOffCycleTest extends HrTestCase
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

    /** Pay and disburse the first August cycle, so it counts as settled. */
    private function settleFirstHalf(): PayrollRun
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        foreach ($run->payslips as $slip) {
            $payroll->markDone($slip, $this->hrUser());
        }

        return $run->fresh();
    }

    // --- scenario one: a back-dated raise ---------------------------------------------------------

    /** A raise dated into a settled period cannot be absorbed by re-running it — so it is flagged. */
    public function test_a_back_dated_raise_into_a_settled_period_is_flagged(): void
    {
        $this->settleFirstHalf();

        app(EmployeeService::class)->update($this->employee, [
            'base_salary' => 50000,
            'salary_effective_date' => '2026-08-01',
            // FIN-007 — backdating into a finished run is refused unless it is acknowledged. The UI
            // raises a confirm dialog for exactly this; the flag is what the user pressing OK sends.
            'acknowledge_repricing' => true,
        ], $this->hrUser());

        $flag = RetroFlag::whereNull('resolved_at')->latest('id')->first();

        $this->assertNotNull($flag, 'a raise into a paid period must not pass silently');
        $this->assertSame(RetroFlag::OWED, $flag->direction);
        $this->assertSame($this->employee->id, $flag->employee_id);
    }

    /**
     * A raise into a period NOTHING has paid yet needs no flag — the next run simply prices it.
     *
     * Note the two different thresholds this exposes, which is worth stating: the backdating CONFIRM
     * gate (FIN-007) trips on any `completed` run, while the retro FLAG (FIN-009) only fires once a run
     * is locked or its payslip disbursed. A merely computed period is past the first and short of the
     * second — so it warns and records nothing, which is right: re-running it absorbs the change.
     */
    public function test_a_raise_with_no_paid_period_behind_it_is_not_flagged(): void
    {
        // No run at all for August yet.
        app(EmployeeService::class)->update($this->employee, [
            'base_salary' => 50000,
            'salary_effective_date' => '2026-08-01',
        ], $this->hrUser());

        $this->assertSame(0, RetroFlag::whereNull('resolved_at')->count());
    }

    /** A computed-but-unpaid period warns (it must be acknowledged) yet raises no outstanding flag. */
    public function test_a_computed_period_warns_but_records_no_difference(): void
    {
        app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        app(EmployeeService::class)->update($this->employee, [
            'base_salary' => 50000,
            'salary_effective_date' => '2026-08-01',
            'acknowledge_repricing' => true,
        ], $this->hrUser());

        $this->assertSame(
            0,
            RetroFlag::whereNull('resolved_at')->count(),
            'nothing was paid yet, so there is no difference to owe',
        );
    }

    /**
     * THE FULL JOURNEY. Raise → flag → off-cycle adjustment run → an adjustment payslip carrying the
     * difference, with the flag cleared. This is the path the audit's FIN-007 → FIN-009 → FIN-010 chain
     * was built for, and nothing walked it from end to end before.
     */
    public function test_an_adjustment_run_settles_the_flagged_difference(): void
    {
        $this->settleFirstHalf();

        app(EmployeeService::class)->update($this->employee, [
            'base_salary' => 50000,
            'salary_effective_date' => '2026-08-01',
            // FIN-007 — backdating into a finished run is refused unless it is acknowledged. The UI
            // raises a confirm dialog for exactly this; the flag is what the user pressing OK sends.
            'acknowledge_repricing' => true,
        ], $this->hrUser());

        $flag = RetroFlag::whereNull('resolved_at')->latest('id')->firstOrFail();

        // What the raise is worth for the settled half — HR enters the figure, as FIN-010 intends.
        $difference = 5000.00;

        $run = app(PayrollService::class)->runAdjustment(
            '2026-08-01',
            '2026-08-15',
            'Back pay for the 1 August raise',
            [[
                'employee_id' => $this->employee->id,
                'amount' => $difference,
                'note' => 'Raise effective 1 Aug, applied after the period was paid',
            ]],
            $this->hrUser(),
        );

        $slip = $run->payslips()->firstOrFail();

        $this->assertSame('adjustment', $run->type);
        $this->assertSame($difference, round((float) $slip->adjustment_pay, 2));
        $this->assertSame($difference, round((float) $slip->gross, 2));

        // The adjustment is taxable — back pay is compensation, not a gift.
        $this->assertSame($difference, round((float) $slip->taxable_pay, 2));

        // And it explains itself on the document (FIN-011).
        $this->assertNotNull($slip->adjustment_note);

        // Resolving the flag takes a note and clears it from the queue.
        app(\App\Modules\Hr\Services\RetroFlagService::class)
            ->resolve($flag, $this->hrUser(), 'Paid on the ' . $run->cycle_start->toDateString() . ' adjustment run');

        $this->assertNotNull($flag->fresh()->resolved_at);
        $this->assertSame(0, RetroFlag::whereNull('resolved_at')->count());
    }

    /** The adjustment must not disturb the regular run for the same dates. */
    public function test_the_adjustment_run_does_not_collide_with_the_regular_run(): void
    {
        $regular = $this->settleFirstHalf();

        $adjustment = app(PayrollService::class)->runAdjustment(
            '2026-08-01',
            '2026-08-15',
            'Back pay',
            [['employee_id' => $this->employee->id, 'amount' => 1000]],
            $this->hrUser(),
        );

        $this->assertNotSame($regular->id, $adjustment->id);
        $this->assertSame('disbursed', $regular->payslips()->first()->status, 'untouched');
        $this->assertSame(2, PayrollRun::whereDate('cycle_start', '2026-08-01')->count());
    }

    // --- scenario two: a back-dated attendance correction -----------------------------------------

    /**
     * REFUSED, not adjusted (FIN-008). Attendance is the input payroll priced the day from; rewriting it
     * behind a disbursed payslip would leave the record and the payment permanently disagreeing, with the
     * payment being the thing that actually happened.
     */
    public function test_correcting_attendance_in_a_settled_period_is_refused(): void
    {
        $this->settleFirstHalf();

        try {
            app(AttendanceService::class)->adjust($this->employee, '2026-08-05', ['status' => 'absent']);
            $this->fail('attendance behind a disbursed payslip must not be editable');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('adjust this attendance record', $e->getMessage());
        }
    }

    /** A merely COMPUTED period stays editable — re-running it is the correct way to absorb the change. */
    public function test_correcting_attendance_in_an_open_period_is_allowed(): void
    {
        app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $corrected = app(AttendanceService::class)->adjust($this->employee, '2026-08-05', ['status' => 'absent']);

        $this->assertSame('absent', $corrected->status);
    }

    /** And once the run is unlocked and the payslip reopened, the correction becomes possible again. */
    public function test_the_correction_becomes_possible_after_the_payslip_is_reopened(): void
    {
        $payroll = app(PayrollService::class);
        $run = $this->settleFirstHalf();

        $payroll->reopenPayslip($run->payslips()->firstOrFail(), 'Correcting attendance');

        $corrected = app(AttendanceService::class)->adjust($this->employee, '2026-08-05', ['status' => 'absent']);

        $this->assertSame('absent', $corrected->status);
    }

    // --- scenario three: a late-approved reimbursement --------------------------------------------

    /**
     * NO ADJUSTMENT NEEDED. An approved claim is picked up by the NEXT run — which is what the feature
     * spec says ("approved claims roll into the next payroll run"), and why a late approval needs no
     * off-cycle payslip.
     */
    public function test_a_late_approved_reimbursement_rolls_into_the_next_run(): void
    {
        $this->settleFirstHalf();

        $claim = Reimbursement::create([
            'employee_id' => $this->employee->id,
            'category' => 'transport',
            'expense_date' => '2026-08-04',
            'amount' => 750.25,
            'description' => 'Client visit',
            'status' => 'pending',
        ]);

        // Approved after the period it belongs to was already paid.
        app(ReimbursementService::class)->approve($claim, $this->hrUser());

        $next = app(PayrollService::class)->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());
        $slip = $next->payslips()->firstOrFail();

        $this->assertSame(750.25, round((float) $slip->reimbursements, 2));
        $this->assertSame('scheduled', $claim->fresh()->status);
        $this->assertSame($next->id, $claim->fresh()->payroll_run_id);
    }

    /** A reimbursement is not compensation, so it must not be taxed on the way through. */
    public function test_the_rolled_reimbursement_is_not_taxed(): void
    {
        $this->settleFirstHalf();

        $claim = Reimbursement::create([
            'employee_id' => $this->employee->id,
            'category' => 'transport',
            'expense_date' => '2026-08-04',
            'amount' => 750.25,
            'description' => 'Client visit',
            'status' => 'pending',
        ]);
        app(ReimbursementService::class)->approve($claim, $this->hrUser());

        $withClaim = app(PayrollService::class)->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());
        $slip = $withClaim->payslips()->firstOrFail();

        // Gross carries it; taxable does not.
        $this->assertSame(
            round((float) $slip->gross - 750.25, 2),
            round((float) $slip->taxable_pay + (float) $slip->sss + (float) $slip->philhealth
                + (float) $slip->pagibig - (float) $slip->absence_deduction, 2),
            'the claim adds to gross without adding to what is taxed',
        );
    }

    /** Marking the run's payslip disbursed is what finally pays the claim. */
    public function test_disbursing_the_payslip_pays_the_claim(): void
    {
        $this->settleFirstHalf();

        $claim = Reimbursement::create([
            'employee_id' => $this->employee->id,
            'category' => 'transport',
            'expense_date' => '2026-08-04',
            'amount' => 750.25,
            'description' => 'Client visit',
            'status' => 'pending',
        ]);
        app(ReimbursementService::class)->approve($claim, $this->hrUser());

        $payroll = app(PayrollService::class);
        $next = $payroll->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());
        $payroll->markDone($next->payslips()->firstOrFail(), $this->hrUser());

        $this->assertSame('paid', $claim->fresh()->status);
    }
}
