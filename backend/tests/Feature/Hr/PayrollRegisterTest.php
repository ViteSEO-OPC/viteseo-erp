<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * FIN-042 — the payroll register, and FIN-041 / FIN-017 — filters on the payroll reports.
 *
 * The only per-employee financial listing was the run CSV export: a file you download, open in a
 * spreadsheet and add up by hand. No on-screen register, no department subtotal, no comparison against
 * the previous period — so the standard pre-disbursement check (register → totals → variance) had no
 * supporting screen. The payroll report itself listed every completed run ever with no way to narrow it.
 *
 * `test_the_register_reconciles_to_the_runs_own_totals` IS THE FINDING'S OWN VALIDATION, and the reason
 * `pesoExact()` had to exist. `ReportService::peso()` is `number_format($n, 0)` — fine for a headline
 * stat, fatal for a reconciliation view, because rounding every row to whole pesos guarantees the totals
 * cannot match `hr_payroll_runs.total_*`. A reconciliation screen that does not reconcile is worse than
 * none: it looks authoritative while being off by the sum of everyone's centavos.
 *
 * THE FILTER TESTS CHECK THE LABELS, not just the figures. A number labelled "YTD" while a date range is
 * applied is precisely the FIN-052 defect in a new place — there, a "Net paid" label sat over a sum of
 * merely-computed runs. Scope belongs in the label.
 */
class PayrollRegisterTest extends HrTestCase
{
    use RefreshDatabase;

    private Department $ops;

    private Department $finance;

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

        $this->ops = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->finance = Department::create(['name' => 'Finance', 'code' => 'FIN']);
    }

    private function employee(string $no, Department $dept, float $salary = 30000): Employee
    {
        $employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => $salary, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $tz = config('app.timezone_local');
        for ($day = Carbon::parse('2026-07-01', $tz); $day->lt(Carbon::parse('2026-09-01', $tz)); $day->addDay()) {
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

    /** Two departments, salaries chosen to leave real centavos in the statutory figures. */
    private function staffAndRun(): PayrollRun
    {
        $this->employee('E-001', $this->ops, 30500.55);
        $this->employee('E-002', $this->ops, 27333.33);
        $this->employee('E-003', $this->finance, 41777.77);

        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    /** @param array<string, mixed> $filters */
    private function register(array $filters = []): array
    {
        return app(ReportService::class)->get('payroll_register', $filters);
    }

    private function money(string $display): float
    {
        return (float) str_replace(['₱', ','], '', $display);
    }

    // --- the register ----------------------------------------------------------------------------

    /**
     * THE FINDING'S VALIDATION. Register totals must equal the run's stored figures to the centavo.
     */
    public function test_the_register_reconciles_to_the_runs_own_totals(): void
    {
        $run = $this->staffAndRun();

        $report = $this->register();

        $this->assertSame(
            round((float) $run->total_gross, 2),
            $this->money($report['summary'][0]['value']),
            'gross must match hr_payroll_runs.total_gross exactly',
        );
        $this->assertSame(
            round((float) $run->total_net, 2),
            $this->money($report['summary'][2]['value']),
            'net must match hr_payroll_runs.total_net exactly',
        );
        $this->assertStringContainsString('reconcile', $report['note']);
    }

    /** The reason `pesoExact()` exists: whole pesos cannot reconcile. */
    public function test_every_figure_carries_centavos(): void
    {
        $this->staffAndRun();

        $report = $this->register();

        foreach ($report['summary'] as $stat) {
            if (! str_starts_with($stat['value'], '₱')) {
                continue; // the employee count
            }
            $this->assertMatchesRegularExpression('/^₱[\d,]+\.\d{2}$/', $stat['value']);
        }
    }

    public function test_it_lists_one_row_per_employee_plus_a_subtotal_per_department(): void
    {
        $this->staffAndRun();

        $rows = $this->register()['table']['rows'];

        // 3 employees + 2 department subtotals.
        $this->assertCount(5, $rows);
        $this->assertSame('— Finance subtotal —', $rows[1][0], 'Finance sorts first, one member');
        $this->assertSame('— Ops subtotal —', $rows[4][0]);
    }

    /** A subtotal has to be the sum of the rows above it, or it is decoration. */
    public function test_a_department_subtotal_equals_its_own_rows(): void
    {
        $this->staffAndRun();

        $rows = $this->register()['table']['rows'];

        // Ops is rows 2 and 3, its subtotal row 4. Column 6 is Gross (0 Employee, 1 Department, then
        // basic/allowances/premiums/other = 2..5).
        $opsGross = $this->money($rows[2][6]) + $this->money($rows[3][6]);

        $this->assertSame(round($opsGross, 2), $this->money($rows[4][6]));
    }

    public function test_the_columns_cover_gross_to_net(): void
    {
        $this->staffAndRun();

        $columns = $this->register()['table']['columns'];

        foreach (['Employee', 'Department', 'Basic', 'Gross', 'SSS/PH/PI', 'Tax', 'Net pay'] as $expected) {
            $this->assertContains($expected, $columns);
        }
    }

    // --- variance ---------------------------------------------------------------------------------

    /** Against the previous run of the SAME pay group — not simply the previous run. */
    public function test_the_variance_compares_the_previous_period(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-07-16', '2026-07-31', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $variance = $this->register()['variance'];

        $this->assertNotNull($variance);
        $this->assertStringContainsString('Jul 16', $variance['title']);
        $this->assertSame(['Line', 'This run', 'Previous', 'Change', '%'], $variance['columns']);
    }

    /**
     * A 13th-month run must not be compared against a semi-monthly one — the swing would be
     * meaningless, and the question is "did anything move since last payday for these people".
     */
    public function test_the_variance_ignores_a_different_run_type(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-07-16', '2026-07-31', 'semi_monthly', $this->hrUser());
        $thirteenth = $payroll->runThirteenthMonth(2026, $this->hrUser());

        $variance = $this->register(['run' => $thirteenth->id])['variance'];

        $this->assertNull($variance, 'no earlier 13th-month run to compare against');
    }

    /** No percentage against a zero base — "+∞%" is not information. */
    public function test_a_zero_previous_figure_shows_no_percentage(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-07-16', '2026-07-31', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $rows = $this->register()['variance']['rows'];
        // "Other earnings" is zero in both periods here.
        $other = collect($rows)->firstWhere(0, 'Other earnings');

        $this->assertSame('—', $other[4]);
    }

    // --- the department filter --------------------------------------------------------------------

    /**
     * A department filter legitimately cannot match the run's own totals, and the note must SAY so —
     * otherwise the register looks like it disagrees with payroll.
     */
    public function test_a_department_filter_says_it_is_a_subset(): void
    {
        $run = $this->staffAndRun();

        $report = $this->register(['department' => $this->finance->id]);

        $this->assertCount(2, $report['table']['rows'], 'one employee + one subtotal');
        $this->assertLessThan(
            round((float) $run->total_gross, 2),
            $this->money($report['summary'][0]['value']),
        );
        $this->assertStringContainsString('subset', $report['note']);
    }

    // --- empty and HTTP ---------------------------------------------------------------------------

    public function test_it_says_so_when_there_is_no_run(): void
    {
        $report = $this->register();

        $this->assertNull($report['table']);
        $this->assertStringContainsString('No completed payroll run', $report['note']);
    }

    public function test_hr_can_read_the_register(): void
    {
        $this->staffAndRun();

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reports/payroll_register')
            ->assertOk()
            ->assertJsonPath('data.title', 'Payroll Register');
    }

    public function test_an_employee_cannot_read_the_register(): void
    {
        $employee = $this->employee('E-001', $this->ops);

        $this->actingAs($employee->user)
            ->getJson('/api/v1/hr/reports/payroll_register')
            ->assertForbidden();
    }

    /** It exports CSV — a register is a table finance reconciles, not a chart. */
    public function test_it_exports_as_csv(): void
    {
        $this->staffAndRun();

        $this->assertSame('csv', app(ReportService::class)->exportFormat('payroll_register'));

        $this->actingAs($this->hrUser())
            ->postJson('/api/v1/hr/reports/payroll_register/export')
            ->assertSuccessful();
    }

    // --- FIN-041 / FIN-017: filters on the payroll + compensation reports -------------------------

    /** @param array<string, mixed> $filters */
    private function payrollReport(array $filters = []): array
    {
        return app(ReportService::class)->get('payroll', $filters);
    }

    public function test_the_payroll_report_narrows_to_a_date_range(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-07-16', '2026-07-31', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $this->assertCount(2, $this->payrollReport()['table']['rows']);
        $this->assertCount(
            1,
            $this->payrollReport(['from' => '2026-08-01', 'to' => '2026-08-31'])['table']['rows'],
        );
    }

    /**
     * THE LABEL FOLLOWS THE FILTER. A figure labelled YTD while a range is applied is the FIN-052
     * defect in a new place.
     */
    public function test_the_payroll_labels_state_their_own_scope(): void
    {
        $this->staffAndRun();

        $this->assertStringContainsString('(YTD)', $this->payrollReport()['summary'][0]['label']);
        $this->assertStringContainsString(
            '(filtered)',
            $this->payrollReport(['from' => '2026-08-01'])['summary'][0]['label'],
        );
    }

    public function test_the_payroll_report_narrows_to_a_pay_group(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-07-01', '2026-07-31', 'monthly', $this->hrUser());

        $rows = $this->payrollReport(['frequency' => 'monthly'])['table']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Monthly', $rows[0][1], 'the pay-group column');
    }

    /** Only pay groups that actually have runs — offering one with no data is a filter to nowhere. */
    public function test_the_payroll_report_offers_the_pay_groups_that_exist(): void
    {
        $this->staffAndRun();

        $this->assertSame(['semi_monthly'], $this->payrollReport()['pay_groups']);
    }

    /**
     * A run header is company-wide and cannot be split, so under a department filter every figure is
     * recomputed from payslips — and the note says so rather than letting the reader assume otherwise.
     */
    public function test_the_payroll_report_recomputes_from_payslips_for_one_department(): void
    {
        $run = $this->staffAndRun();

        $filtered = $this->payrollReport(['department' => $this->finance->id]);

        $this->assertStringContainsString('cannot be split', $filtered['note']);
        $this->assertLessThan(
            (float) $run->total_net,
            $this->money($filtered['table']['rows'][0][4]),
            'the department’s net is a fraction of the run’s',
        );
    }

    public function test_the_compensation_report_narrows_to_a_date_range(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-07-16', '2026-07-31', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $all = app(ReportService::class)->get('compensation');
        $ranged = app(ReportService::class)->get('compensation', ['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertStringContainsString('(YTD)', $all['summary'][0]['label']);
        $this->assertStringContainsString('(filtered)', $ranged['summary'][0]['label']);
        $this->assertLessThan(
            $this->money($all['summary'][0]['value']),
            $this->money($ranged['summary'][0]['value']),
        );
    }

    /** The compensation report has its own bespoke screen, so its filter options must be in ITS payload. */
    public function test_the_compensation_report_offers_its_own_filter_options(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-07-01', '2026-07-31', 'monthly', $this->hrUser());

        $report = app(ReportService::class)->get('compensation');

        $this->assertSame(['monthly', 'semi_monthly'], $report['pay_groups']);
        $this->assertNotEmpty($report['departments']);
    }

    public function test_the_compensation_report_narrows_to_a_pay_group(): void
    {
        $this->employee('E-001', $this->ops);
        $payroll = app(PayrollService::class);
        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-07-01', '2026-07-31', 'monthly', $this->hrUser());

        $all = app(ReportService::class)->get('compensation');
        $monthly = app(ReportService::class)->get('compensation', ['frequency' => 'monthly']);

        $this->assertLessThan(
            $this->money($all['summary'][0]['value']),
            $this->money($monthly['summary'][0]['value']),
        );
        $this->assertStringContainsString('(filtered)', $monthly['summary'][0]['label']);
    }

    public function test_the_compensation_report_narrows_to_a_department(): void
    {
        $this->staffAndRun();

        $report = app(ReportService::class)->get('compensation', ['department' => $this->finance->id]);
        $all = app(ReportService::class)->get('compensation');

        $this->assertLessThan(
            $this->money($all['summary'][0]['value']),
            $this->money($report['summary'][0]['value']),
        );
    }

    public function test_the_filters_are_forwarded_over_http(): void
    {
        $this->staffAndRun();

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reports/payroll?from=2026-08-01&to=2026-08-31&frequency=semi_monthly')
            ->assertOk()
            ->assertJsonPath('data.summary.0.label', fn ($v) => str_contains((string) $v, '(filtered)'));
    }
}
