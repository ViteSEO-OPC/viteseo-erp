<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PaymentBatch;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PaymentService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Every artifact a payroll run produces must agree with every other one. */
class PayrollReconciliationTest extends HrTestCase
{
    use RefreshDatabase;

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
    }

    /**
     * Five employees across two departments on salaries chosen to leave centavos everywhere, one of them
     * with an absence so the run is not uniform.
     */
    private function staff(): void
    {
        $ops = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $fin = Department::create(['name' => 'Finance', 'code' => 'FIN']);

        $people = [
            ['E-001', $ops, 30500.55, true],
            ['E-002', $ops, 27333.33, true],
            ['E-003', $ops, 41777.77, false], // one absent day — the run must not be uniform
            ['E-004', $fin, 52111.11, true],
            ['E-005', $fin, 19999.99, true],
        ];

        foreach ($people as [$no, $dept, $salary, $fullAttendance]) {
            $employee = Employee::create([
                'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
                'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
                'employment_status' => 'regular',
                'base_salary' => $salary, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
                'bank_name' => 'BPI', 'bank_account' => '10000' . $no,
                'bank_account_name' => 'Test ' . $no, 'bank_account_type' => 'Savings',
            ]);

            $tz = config('app.timezone_local');
            $skip = $fullAttendance ? null : '2026-08-05';

            for ($day = Carbon::parse('2026-08-01', $tz); $day->day <= 15 && $day->month === 8; $day->addDay()) {
                if ($day->isWeekend() || $day->toDateString() === $skip) {
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
        }
    }

    /** Named `runCycle`, not `run` — PHPUnit's TestCase::run() is final. */
    private function runCycle(): PayrollRun
    {
        $this->staff();

        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    /** "₱1,234.56" → 1234.56, for reading figures back out of a display string. */
    private function money(string $display): float
    {
        return (float) str_replace(['₱', ','], '', $display);
    }

    // --- leg by leg ------------------------------------------------------------------------------

    /** The stored header must equal the payslips it was computed from. */
    public function test_the_run_header_equals_the_sum_of_its_payslips(): void
    {
        $run = $this->runCycle();

        $this->assertSame(
            round((float) $run->payslips()->sum('gross'), 2),
            round((float) $run->total_gross, 2),
        );
        $this->assertSame(
            round((float) $run->payslips()->sum('net_pay'), 2),
            round((float) $run->total_net, 2),
        );
        $this->assertSame(
            round((float) $run->payslips()->sum('total_deductions'), 2),
            round((float) $run->total_deductions, 2),
        );
        $this->assertSame($run->payslips()->count(), (int) $run->employee_count);
    }

    /** The register re-sums the payslips independently — it must land on the same figures. */
    public function test_the_register_equals_the_run_header(): void
    {
        $run = $this->runCycle();

        $report = app(ReportService::class)->get('payroll_register', ['run' => $run->id]);

        $this->assertSame(round((float) $run->total_gross, 2), $this->money($report['summary'][0]['value']));
        $this->assertSame(round((float) $run->total_net, 2), $this->money($report['summary'][2]['value']));
        $this->assertStringContainsString('reconcile', $report['note']);
    }

    /** Every department subtotal added together must equal the whole run — no employee dropped. */
    public function test_the_register_department_subtotals_sum_to_the_run(): void
    {
        $run = $this->runCycle();

        $rows = app(ReportService::class)->get('payroll_register', ['run' => $run->id])['table']['rows'];

        // Column 6 is Gross, 10 is Net (0 Employee, 1 Department, then 4 earnings columns).
        $subtotalGross = 0.0;
        $subtotalNet = 0.0;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], '— ')) {
                $subtotalGross += $this->money($row[6]);
                $subtotalNet += $this->money($row[10]);
            }
        }

        $this->assertSame(round((float) $run->total_gross, 2), round($subtotalGross, 2));
        $this->assertSame(round((float) $run->total_net, 2), round($subtotalNet, 2));
    }

    /**
     * The run CSV's TOTAL row sums its own per-employee columns. If those disagree with the header, the
     * file finance opens does not match the screen they approved it on.
     */
    public function test_the_run_csv_total_row_equals_the_run_header(): void
    {
        $run = $this->runCycle();

        $csv = app(PayrollService::class)->runCsv($run);
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $total = str_getcsv($lines[count($lines) - 1]);

        $this->assertSame('TOTAL', $total[0]);
        // Column 9 is Gross, 15 Deductions, 16 Net — see runCsv()'s TOTAL row.
        $this->assertSame(round((float) $run->total_gross, 2), round((float) $total[9], 2));
        $this->assertSame(round((float) $run->total_deductions, 2), round((float) $total[15], 2));
        $this->assertSame(round((float) $run->total_net, 2), round((float) $total[16], 2));
    }

    /** And its per-employee rows must add up to that TOTAL row, not merely sit above it. */
    public function test_the_run_csv_rows_sum_to_its_own_total_row(): void
    {
        $run = $this->runCycle();

        $lines = array_values(array_filter(explode("\n", trim(app(PayrollService::class)->runCsv($run)))));
        $rows = array_slice($lines, 1, -1); // drop the header and the TOTAL
        $total = str_getcsv($lines[count($lines) - 1]);

        $gross = 0.0;
        $net = 0.0;
        foreach ($rows as $line) {
            $cells = str_getcsv($line);
            $gross += (float) $cells[9];
            $net += (float) $cells[16];
        }

        $this->assertCount(5, $rows, 'one row per employee');
        $this->assertSame(round((float) $total[9], 2), round($gross, 2));
        $this->assertSame(round((float) $total[16], 2), round($net, 2));
    }

    /** The bank batch is what leaves the company — it must equal the run's net to the centavo. */
    public function test_the_bank_batch_equals_the_runs_net(): void
    {
        $run = $this->runCycle();

        app(PaymentService::class)->buildForRun($run, 'bank_transfer', null, $this->hrUser());
        $batch = PaymentBatch::where('payroll_run_id', $run->id)->latest('id')->firstOrFail();

        $check = app(PaymentService::class)->reconcileWithRun($batch);

        $this->assertTrue($check['balanced'], 'the batch must balance against the run');
        $this->assertSame(round((float) $run->total_net, 2), $check['batch_total']);
        $this->assertSame(0.0, $check['difference']);
        $this->assertSame(0, $check['unpaid_payslips']);
    }

    /** And the individual payments must add up to the batch header. */
    public function test_the_payments_sum_to_the_batch_total(): void
    {
        $run = $this->runCycle();

        app(PaymentService::class)->buildForRun($run, 'bank_transfer', null, $this->hrUser());
        $batch = PaymentBatch::where('payroll_run_id', $run->id)->latest('id')->firstOrFail();

        $this->assertSame(
            round((float) $batch->payments()->sum('amount'), 2),
            round((float) $batch->total_amount, 2),
        );
        $this->assertSame($batch->payments()->count(), (int) $batch->payment_count);
    }

    // --- all of it at once -----------------------------------------------------------------------

    /**
     * THE ONE THAT MATTERS. All five artifacts, one assertion set, so a future change that moves one of
     * them fails here rather than in somebody's spreadsheet.
     */
    public function test_every_artifact_agrees_on_the_same_net(): void
    {
        $run = $this->runCycle();
        app(PaymentService::class)->buildForRun($run, 'bank_transfer', null, $this->hrUser());
        $run = $run->fresh();

        $header = round((float) $run->total_net, 2);
        $payslips = round((float) $run->payslips()->sum('net_pay'), 2);

        $register = $this->money(
            app(ReportService::class)->get('payroll_register', ['run' => $run->id])['summary'][2]['value'],
        );

        $lines = array_values(array_filter(explode("\n", trim(app(PayrollService::class)->runCsv($run)))));
        $csv = round((float) str_getcsv($lines[count($lines) - 1])[16], 2);

        $batch = round((float) PaymentBatch::where('payroll_run_id', $run->id)->latest('id')->firstOrFail()->total_amount, 2);

        $this->assertGreaterThan(0, $header, 'a zero run would reconcile trivially');
        foreach (['payslips' => $payslips, 'register' => $register, 'csv' => $csv, 'bank batch' => $batch] as $label => $figure) {
            $this->assertSame($header, $figure, "the {$label} disagrees with the run header");
        }
    }

    /** The same, for gross — deductions are where the rounding lives. */
    public function test_every_artifact_agrees_on_the_same_gross(): void
    {
        $run = $this->runCycle();

        $header = round((float) $run->total_gross, 2);
        $payslips = round((float) $run->payslips()->sum('gross'), 2);
        $register = $this->money(
            app(ReportService::class)->get('payroll_register', ['run' => $run->id])['summary'][0]['value'],
        );
        $lines = array_values(array_filter(explode("\n", trim(app(PayrollService::class)->runCsv($run)))));
        $csv = round((float) str_getcsv($lines[count($lines) - 1])[9], 2);

        foreach (['payslips' => $payslips, 'register' => $register, 'csv' => $csv] as $label => $figure) {
            $this->assertSame($header, $figure, "the {$label} disagrees on gross");
        }
    }

    /**
     * A run with a leaver and an absence still reconciles. The fixture already has an absence; this adds
     * a mid-cycle separation, which is the case that prorates and therefore the case most likely to make
     * one artifact disagree with another.
     */
    public function test_it_still_reconciles_with_a_mid_cycle_leaver(): void
    {
        $this->staff();
        Employee::where('employee_no', 'E-005')->firstOrFail()->update([
            'separation_type' => 'voluntary',
            'separation_date' => '2026-08-08',
        ]);

        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertSame(
            round((float) $run->payslips()->sum('net_pay'), 2),
            round((float) $run->total_net, 2),
        );

        $report = app(ReportService::class)->get('payroll_register', ['run' => $run->id]);
        $this->assertSame(round((float) $run->total_net, 2), $this->money($report['summary'][2]['value']));
        $this->assertStringContainsString('reconcile', $report['note']);
    }

    /**
     * THE NEGATIVE CONTROL. If the payslips are tampered with behind the header's back, the register must
     * NOTICE — otherwise every assertion above passes because both sides read the same column, and this
     * whole file proves nothing.
     */
    public function test_the_register_reports_a_mismatch_rather_than_hiding_it(): void
    {
        $run = $this->runCycle();

        // Move one payslip without touching the header — what a bad migration or a hand-edit looks like.
        $slip = $run->payslips()->firstOrFail();
        $slip->update(['net_pay' => round((float) $slip->net_pay + 500, 2)]);

        $report = app(ReportService::class)->get('payroll_register', ['run' => $run->id]);

        $this->assertStringContainsString('DO NOT match', $report['note']);
        $this->assertNotSame(
            round((float) $run->total_net, 2),
            $this->money($report['summary'][2]['value']),
        );
    }
}
