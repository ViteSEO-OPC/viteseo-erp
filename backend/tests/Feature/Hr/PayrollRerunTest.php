<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Recomputing a period that has already been run, and saying first what that would do. */
class PayrollRerunTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Employee $colleague;

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

        $this->employee = $this->staff($dept->id, 'E-001');
        $this->colleague = $this->staff($dept->id, 'E-002');
    }

    private function staff(int $deptId, string $no): Employee
    {
        $employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $deptId,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $tz = config('app.timezone_local');
        for ($day = Carbon::parse('2026-08-01', $tz); $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(8, 0)->utc(),
                'time_out' => $day->copy()->setTime(17, 0)->utc(),
                'status' => 'present',
            ]);
        }

        return $employee;
    }

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function period(): PayrollRun
    {
        return $this->payroll()->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    private function preview(PayrollRun $run): array
    {
        return $this->payroll()->rerunPreview($run, $this->hrUser());
    }

    private function slip(PayrollRun $run, ?Employee $employee = null): Payslip
    {
        return Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', ($employee ?? $this->employee)->id)
            ->firstOrFail();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rowFor(array $rows, Employee $employee): array
    {
        foreach ($rows as $row) {
            if ($row['employee_id'] === $employee->id) {
                return $row;
            }
        }

        $this->fail("no preview row for employee {$employee->id}");
    }

    /** Remove a worked day, so the period would reprice lower on a re-run. */
    private function makeAbsent(Employee $employee, string $date = '2026-08-05'): void
    {
        Attendance::where('employee_id', $employee->id)->where('work_date', $date)->delete();
    }

    // --- the preview writes nothing -----------------------------------------------------------

    /**
     * THE CENTRAL GUARANTEE. The preview runs payroll for real inside a transaction it rolls back;
     * if any of that survived, opening a confirmation dialog would silently recompute a settled
     * period — the exact thing the dialog exists to ask about.
     */
    public function test_a_preview_leaves_the_period_exactly_as_it_was(): void
    {
        $run = $this->period();
        $before = $this->slip($run);
        $netBefore = (float) $before->net_pay;
        $slipCount = Payslip::count();
        $runCount = PayrollRun::count();

        $this->makeAbsent($this->employee);
        $preview = $this->preview($run);

        // The preview must SEE the change, or this test would pass on a preview that computes nothing.
        $this->assertSame(1, $preview['meta']['changed']);

        $this->assertSame($netBefore, (float) $this->slip($run)->net_pay);
        $this->assertSame($before->id, $this->slip($run)->id);
        $this->assertSame($slipCount, Payslip::count());
        $this->assertSame($runCount, PayrollRun::count());
        $this->assertSame('completed', $run->fresh()->status, 'the run was left mid-recompute');
    }

    /** And it writes no audit trail: a question that logs itself as a run is a lie in the record. */
    public function test_a_preview_records_no_audit_line(): void
    {
        $run = $this->period();
        $lines = AuditLog::count();

        $this->preview($run);

        $this->assertSame($lines, AuditLog::count());
    }

    // --- what it reports ----------------------------------------------------------------------

    public function test_it_states_the_before_and_after_for_a_figure_that_moves(): void
    {
        $run = $this->period();
        $netBefore = (float) $this->slip($run)->net_pay;

        $this->makeAbsent($this->employee);
        $preview = $this->preview($run);
        $row = $this->rowFor($preview['rows'], $this->employee);

        $this->assertSame('changed', $row['change']);
        $this->assertSame($netBefore, $row['before']['net']);
        $this->assertLessThan($netBefore, $row['after']['net'], 'a missing day must cost pay');
        $this->assertSame(round($row['after']['net'] - $netBefore, 2), $row['delta']);
        $this->assertSame($preview['meta']['delta'], round($preview['meta']['net_after'] - $preview['meta']['net_before'], 2));
    }

    public function test_an_untouched_employee_reads_as_unchanged_with_a_zero_delta(): void
    {
        $run = $this->period();

        $this->makeAbsent($this->employee);
        $row = $this->rowFor($this->preview($run)['rows'], $this->colleague);

        $this->assertSame('unchanged', $row['change']);
        $this->assertSame(0.0, $row['delta']);
        $this->assertSame($row['before']['net'], $row['after']['net']);
    }

    /** A DISBURSED PAYSLIP IS MARKED SKIPPED, NOT DIFFED. */
    public function test_a_disbursed_payslip_is_reported_as_skipped_and_not_repriced(): void
    {
        $run = $this->period();
        $this->payroll()->markDone($this->slip($run), $this->hrUser());
        $netPaid = (float) $this->slip($run)->net_pay;

        $this->makeAbsent($this->employee);
        $row = $this->rowFor($this->preview($run)['rows'], $this->employee);

        $this->assertSame('skipped', $row['change']);
        $this->assertSame('disbursed', $row['status']);
        $this->assertSame($netPaid, $row['after']['net'], 'a paid payslip must be shown at what it is');
        $this->assertSame(0.0, $row['delta']);
        $this->assertSame(1, $this->preview($run)['meta']['skipped']);
    }

    /** An employee with no payslip in the period who now qualifies for one reads as added. */
    public function test_an_employee_who_would_gain_a_payslip_reads_as_added(): void
    {
        $run = $this->period();
        $newcomer = $this->staff($this->employee->department_id, 'E-003');
        // Hired after the period ran, so the stored run has no payslip for them.
        $newcomer->update(['hire_date' => '2026-08-01']);

        $row = $this->rowFor($this->preview($run)['rows'], $newcomer);

        $this->assertSame('added', $row['change']);
        $this->assertNull($row['before']);
        $this->assertGreaterThan(0, $row['after']['net']);
    }

    /** Re-running is what resolves a stale tax mark, so the preview says the recompute has that job. */
    public function test_it_says_when_the_recompute_would_clear_a_stale_tax_mark(): void
    {
        $run = $this->period();

        $this->assertFalse($this->preview($run)['meta']['clears_tax_stale']);

        $run->update(['tax_stale_at' => now(), 'tax_stale_reason' => 'An earlier period was corrected.']);

        $this->assertTrue($this->preview($run->fresh())['meta']['clears_tax_stale']);
    }

    // --- the re-run itself --------------------------------------------------------------------

    public function test_the_rerun_applies_what_the_preview_said(): void
    {
        $run = $this->period();

        $this->makeAbsent($this->employee);
        $predicted = $this->rowFor($this->preview($run)['rows'], $this->employee)['after']['net'];

        $this->payroll()->rerunRun($run, 'August attendance was corrected after the cutoff', $this->hrUser());

        $this->assertSame($predicted, round((float) $this->slip($run)->net_pay, 2));
    }

    public function test_the_rerun_records_its_reason(): void
    {
        $run = $this->period();

        $this->payroll()->rerunRun($run, 'August attendance was corrected after the cutoff', $this->hrUser());

        $this->assertTrue(
            AuditLog::where('description', 'like', '%Re-ran%')
                ->where('description', 'like', '%attendance was corrected after the cutoff%')
                ->exists(),
            'the reason for recomputing a settled period must be on the record',
        );
    }

    public function test_a_reason_is_required(): void
    {
        $run = $this->period();

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/rerun", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_the_endpoint_recomputes_and_returns_the_run(): void
    {
        $run = $this->period();
        $this->makeAbsent($this->employee);

        $this->hrPost("/api/v1/hr/payroll/runs/{$run->id}/rerun", [
            'reason' => 'August attendance was corrected after the cutoff',
        ])->assertOk()->assertJsonPath('data.id', $run->id);

        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_the_preview_endpoint_returns_rows_and_totals(): void
    {
        $run = $this->period();
        $this->makeAbsent($this->employee);

        $response = $this->actingAsHr()
            ->getJson("/api/v1/hr/payroll/runs/{$run->id}/rerun-preview")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(1, $response->json('meta.changed'));
        $this->assertLessThan(0, $response->json('meta.delta'));
    }

    // --- what it refuses ----------------------------------------------------------------------

    public function test_a_locked_run_cannot_be_recomputed_or_previewed(): void
    {
        $run = $this->period();
        $run->update(['locked_at' => now(), 'locked_by' => $this->hrUser()->id]);

        foreach (['preview', 'rerun'] as $attempt) {
            try {
                $attempt === 'preview'
                    ? $this->preview($run->fresh())
                    : $this->payroll()->rerunRun($run->fresh(), 'It needs to change', $this->hrUser());
                $this->fail("a locked run must refuse to {$attempt}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('locked', $e->getMessage());
            }
        }
    }

    /**
     * A 13th-month run's window is 1–31 December, which overlaps every December cutoff. Feeding its
     * dates back through the regular engine would compute an ordinary period and pay everyone for
     * it, so this refuses instead of guessing.
     */
    public function test_a_thirteenth_month_run_is_not_recomputed_as_a_pay_period(): void
    {
        $run = PayrollRun::create([
            'cycle_start' => '2026-12-01', 'cycle_end' => '2026-12-31',
            'frequency' => 'thirteenth_month', 'type' => 'thirteenth_month',
            'status' => 'completed', 'run_at' => now(), 'run_by' => $this->hrUser()->id,
        ]);

        try {
            $this->payroll()->rerunRun($run, 'It looks wrong', $this->hrUser());
            $this->fail('a 13th-month run must not be recomputed as a pay period');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('13th-month screen', $e->getMessage());
        }

        $this->assertSame(1, PayrollRun::count(), 'nothing may have been created by the refusal');
    }
}
