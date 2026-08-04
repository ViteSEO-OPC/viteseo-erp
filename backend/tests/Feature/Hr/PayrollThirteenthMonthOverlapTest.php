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

/**
 * FIN-001 and FIN-012 (payroll audit, §3) — where a 13th-month run collides with December.
 *
 * WRITTEN TO FAIL. These tests exist to establish that two findings the audit reached by TRACING
 * control flow are real before anyone edits PayrollService for them. The audit is explicit that
 * both were derived, not observed, and that a trace which turns out to be wrong should be struck
 * rather than fixed around. If any test here passes on unmodified code, the corresponding finding
 * is wrong.
 *
 * THE MECHANISM (FIN-001). `run()`'s double-pay guard asks "does this employee already have a
 * payslip in another run whose period overlaps this cycle?" and it filters on dates alone — no
 * `type`, no `frequency`. `runThirteenthMonth()` creates its run at cycle_start {year}-12-01 /
 * cycle_end {year}-12-31, which overlaps every December regular cycle exactly. So a 13th-month
 * payslip reads as "already paid for December".
 *
 * Two outcomes, both tested below: December has not run yet (nobody gets paid), or December has
 * already run (its computed payslips are force-deleted, because `$keep` empties and Laravel
 * compiles `whereNotIn` over an empty array to `1 = 1`).
 *
 * THE MECHANISM (FIN-012). `runThirteenthMonth()` writes with a bare `updateOrCreate` — it lacks
 * both guards `run()` has: the `status === 'disbursed'` skip, and the cleanup that force-deletes
 * payslips for employees who fall out of the computed set on a re-run.
 *
 * THE CLOCK IS FROZEN (the FIN-051 lesson). December 2026 has to be wholly in the past for the
 * regular run to price it from attendance rather than from a half-elapsed month, so `now()` is
 * pinned to January 2027 instead of being left to whatever day the suite runs on.
 */
class PayrollThirteenthMonthOverlapTest extends HrTestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    private const DEC_START = '2026-12-01';

    private const DEC_END = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        // After the cycle under test, so December 2026 is entirely history.
        $this->travelTo(Carbon::parse('2027-01-15 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function employee(string $no, float $salary = 30000): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => $salary, 'pay_frequency' => 'monthly', 'is_management' => false,
            'employment_status' => 'regular',
        ]);
    }

    /**
     * January–November, written directly. Only December is run for real here; the earlier months
     * exist so the 13th-month basis (Σ basic ÷ 12) is non-zero.
     */
    private function payJanToNov(Employee $employee, float $monthlyBasic = 30000): void
    {
        foreach (range(1, 11) as $month) {
            $start = sprintf('%d-%02d-01', self::YEAR, $month);
            $run = PayrollRun::firstOrCreate(
                ['cycle_start' => $start, 'cycle_end' => date('Y-m-t', strtotime($start)), 'frequency' => 'monthly'],
                ['type' => 'regular', 'status' => 'completed', 'run_at' => now()],
            );

            Payslip::create([
                'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                'base_salary' => $monthlyBasic, 'gross' => $monthlyBasic,
                'total_deductions' => 0, 'net_pay' => $monthlyBasic, 'status' => 'disbursed',
            ]);
        }
    }

    /** Present on every December weekday, so the real December run has something to price. */
    private function workedDecember(Employee $employee): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::DEC_END, $tz);

        for ($d = Carbon::parse(self::DEC_START, $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    /** Named `execute`, not `run` — PHPUnit's TestCase::run() is final. */
    private function runDecember(): PayrollRun
    {
        return app(PayrollService::class)->run(
            self::DEC_START, self::DEC_END, 'monthly', User::factory()->create(),
        );
    }

    private function runThirteenth(): PayrollRun
    {
        return app(PayrollService::class)->runThirteenthMonth(self::YEAR, User::factory()->create());
    }

    private function decemberSlip(Employee $employee): ?Payslip
    {
        return Payslip::where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('frequency', 'monthly')
                ->where('cycle_start', self::DEC_START))
            ->first();
    }

    // --- FIN-001 -----------------------------------------------------------------------

    /**
     * (a) 13th month is paid mid-December, then the December cutoff closes. Every employee should
     * still be paid their December salary — the two are different money.
     */
    public function test_december_payroll_still_pays_everyone_after_a_thirteenth_month_run(): void
    {
        $employee = $this->employee('E-001');
        $this->payJanToNov($employee);
        $this->workedDecember($employee);

        $this->runThirteenth();
        $december = $this->runDecember();

        $slip = $this->decemberSlip($employee);

        $this->assertNotNull($slip, 'December payroll produced no payslip for this employee');
        $this->assertGreaterThan(0, (float) $slip->net_pay, 'December net pay must not be zero');
        $this->assertSame(1, $december->employee_count, 'the December run paid nobody');
    }

    /**
     * (b) December has already run. A re-run to correct anything must not destroy the payslips it
     * already produced — every employee is skipped as paid-elsewhere, `$keep` empties, and an
     * empty `whereNotIn` matches every row.
     */
    public function test_re_running_december_after_thirteenth_month_does_not_delete_its_payslips(): void
    {
        $employee = $this->employee('E-001');
        $this->payJanToNov($employee);
        $this->workedDecember($employee);

        $this->runDecember();
        $this->assertNotNull($this->decemberSlip($employee), 'precondition: December ran and produced a payslip');

        $this->runThirteenth();
        $this->runDecember();

        $this->assertNotNull(
            $this->decemberSlip($employee),
            'the re-run force-deleted the December payslip',
        );
    }

    /**
     * THE GUARD MUST STILL FIRE FOR THE CASE IT WAS BUILT FOR.
     *
     * The FIN-001 fix narrows the overlap guard from "any overlapping run" to "any overlapping run
     * of the SAME type". The scenario it exists for — an employee moved to a different pay group
     * after already being run — is two REGULAR runs, so it must still be caught.
     *
     * This is a regression guard, not a reproduction: it passed before the fix too. It is here
     * because nothing covered the guard's original purpose, and narrowing a safety check with no
     * test on the behaviour you are preserving is how one fix becomes the next finding.
     */
    public function test_an_employee_already_paid_by_an_overlapping_regular_run_is_not_paid_twice(): void
    {
        $employee = $this->employee('E-001'); // created in the monthly pay group
        $this->workedDecember($employee);

        $this->runDecember();

        // Moved to the semi-monthly group after December had already been run for them.
        $employee->update(['pay_frequency' => 'semi_monthly']);

        $second = app(PayrollService::class)->run(
            '2026-12-01', '2026-12-15', 'semi_monthly', User::factory()->create(),
        );

        $this->assertSame(0, $second->employee_count, 'paid twice for overlapping December days');
        $this->assertSame(1, Payslip::where('employee_id', $employee->id)->count());
    }

    // --- FIN-012 -----------------------------------------------------------------------

    /** A 13th-month payslip that has been paid out must not be rewritten by a re-run. */
    public function test_re_running_thirteenth_month_leaves_a_disbursed_payslip_alone(): void
    {
        $employee = $this->employee('E-001');
        $this->payJanToNov($employee);

        $run = $this->runThirteenth();
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $paidAmount = (float) $slip->thirteenth_month;

        app(PayrollService::class)->markDone($slip);

        // Change the basis after payment: one month's basic doubles, so a recompute would raise
        // the 13th month by 30,000 ÷ 12 = 2,500.
        Payslip::where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('cycle_start', '2026-01-01'))
            ->update(['base_salary' => 60000]);

        $this->runThirteenth();

        $this->assertSame(
            $paidAmount,
            (float) $slip->fresh()->thirteenth_month,
            'a disbursed 13th-month payslip was rewritten by the re-run',
        );
        $this->assertSame('disbursed', $slip->fresh()->status);
    }

    /** An employee who no longer qualifies must not keep a stale payslip counting toward the run. */
    public function test_re_running_thirteenth_month_removes_payslips_for_employees_who_no_longer_qualify(): void
    {
        $stays = $this->employee('E-001');
        $drops = $this->employee('E-002');
        $this->payJanToNov($stays);
        $this->payJanToNov($drops);

        $run = $this->runThirteenth();
        $this->assertSame(2, Payslip::where('payroll_run_id', $run->id)->count(), 'precondition: both were paid');

        // E-002's year is voided, so Σ basic ÷ 12 is 0 and computeThirteenthMonth skips them.
        Payslip::where('employee_id', $drops->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('frequency', 'monthly'))
            ->update(['base_salary' => 0]);

        $this->runThirteenth();

        // Asserted as a count, not the model — a failing assertNull() on an Eloquent model dumps
        // every attribute and cast, which buries the reason in 200 lines of CI output.
        $this->assertSame(
            0,
            Payslip::where('payroll_run_id', $run->id)->where('employee_id', $drops->id)->count(),
            'a stale 13th-month payslip survived the re-run and still counts toward the run total',
        );
    }
}
