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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;

/** The run export as a WORKBOOK — the copy HR opens rather than the copy a script parses. */
class PayrollWorkbookExportTest extends HrTestCase
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
     * Four people on salaries with centavos in them, one absent for a day so the absence columns
     * carry a figure, and one whose NAME OPENS WITH `=` — the injection the CSV writer neutralises,
     * which in a workbook is a cell Excel would evaluate rather than print.
     */
    private function runCycle(): PayrollRun
    {
        $ops = Department::create(['name' => 'Ops', 'code' => 'OPS']);

        $people = [
            ['E-001', 30500.55, true],
            ['E-002', 27333.33, false], // the absence
            ['E-003', 41777.77, true],
            ['E-004', 19999.99, true],
        ];

        foreach ($people as $i => [$no, $salary, $fullAttendance]) {
            $name = 0 === $i ? '=cmd|calc' : 'Staff ' . $no;

            $employee = Employee::create([
                'user_id' => User::factory()->create(['name' => $name])->id,
                'department_id' => $ops->id,
                'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
                'employment_status' => 'regular',
                'base_salary' => $salary, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            ]);

            $tz = config('app.timezone_local');
            $skip = $fullAttendance ? null : '2026-08-05';

            for ($day = Carbon::parse('2026-08-01', $tz); $day->day <= 15 && 8 === $day->month; $day->addDay()) {
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

        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    /**
     * Every sheet as `['Sheet name' => list of rows]`, read back out of the written file.
     *
     * @return array<string, list<list<mixed>>>
     */
    private function sheets(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $out = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            $out[$sheet->getName()] = $rows;
        }

        $reader->close();

        return $out;
    }

    /**
     * The index of the table's header row.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function headerRow(array $rows): int
    {
        foreach ($rows as $i => $row) {
            if ('Employee' === ($row[0] ?? null)) {
                return $i;
            }
        }

        $this->fail('The breakdown sheet has no header row.');
    }

    /** The header row's labels, so a column is found by NAME and not by a position that can move. */
    private function columnIndex(array $header, string $label): int
    {
        $index = array_search($label, $header, true);

        $this->assertNotFalse($index, "The breakdown sheet has no '{$label}' column.");

        return (int) $index;
    }

    // --- the endpoint ------------------------------------------------------------------------

    /** `?format=xlsx` returns a workbook; the bare request still returns the CSV it always did. */
    public function test_the_endpoint_serves_a_workbook_only_when_asked_for_one(): void
    {
        $run = $this->runCycle();

        $csv = $this->actingAsHr()->get("/api/v1/hr/payroll/runs/{$run->id}/export");
        $csv->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith('Employee,Department,Base,', $csv->getContent());

        $xlsx = $this->actingAsHr()->get("/api/v1/hr/payroll/runs/{$run->id}/export?format=xlsx");
        $xlsx->assertOk();
        $xlsx->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));
    }

    /** An employee has to be able to get neither one. */
    public function test_an_employee_cannot_export_the_run(): void
    {
        $run = $this->runCycle();

        $staff = User::factory()->create();
        $staff->assignRole('hr_employee');
        Sanctum::actingAs($staff);

        $this->get("/api/v1/hr/payroll/runs/{$run->id}/export?format=xlsx")->assertForbidden();
    }

    // --- the file ----------------------------------------------------------------------------

    /** Both sheets exist: the one to read, and the one nothing may be lost from. */
    public function test_it_writes_the_breakdown_and_the_register(): void
    {
        ['path' => $path] = app(PayrollService::class)->runXlsx($this->runCycle());
        $sheets = $this->sheets($path);

        $this->assertSame(['Payroll Export', 'Source Data'], array_keys($sheets));

        // 's remittance columns are the whole reason the second sheet is there.
        $register = $sheets['Source Data'][0];
        foreach (['Employer SSS', 'Employer PhilHealth', 'Employer Pag-IBIG', 'Overridden'] as $column) {
            $this->assertContains($column, $register);
        }

        unlink($path);
    }

    /**
     * THE IDENTITY. Earnings sum to gross, charges sum to total deductions, and the two meet at the
     * stored net pay — for every employee, read out of the cells the file actually contains.
     */
    public function test_every_row_foots_to_the_payslip_it_describes(): void
    {
        $run = $this->runCycle();

        ['path' => $path] = app(PayrollService::class)->runXlsx($run);
        $rows = $this->sheets($path)['Payroll Export'];

        $head = $this->headerRow($rows);
        $header = $rows[$head];
        $gross = $this->columnIndex($header, 'Gross Pay');
        $deductions = $this->columnIndex($header, 'Total Deductions');
        $net = $this->columnIndex($header, 'Net Pay');

        $nets = $run->payslips()->pluck('net_pay')->map(fn ($n) => round((float) $n, 2))->all();
        $seen = [];

        foreach (array_slice($rows, $head + 1, $run->payslips()->count()) as $row) {
            // Earnings are every money column up to and including gross; charges are everything
            // between gross and the total, which the writer stores negative.
            $earnings = round(array_sum(array_slice($row, 2, $gross - 2)), 2);
            $charges = round(array_sum(array_slice($row, $gross + 1, $deductions - $gross - 1)), 2);

            $this->assertSame(round((float) $row[$gross], 2), $earnings, 'Earnings do not sum to gross.');
            $this->assertSame(round((float) $row[$deductions], 2), $charges, 'Charges do not sum to the total.');
            $this->assertSame(
                round((float) $row[$net], 2),
                round((float) $row[$gross] + (float) $row[$deductions], 2),
                'Gross less deductions is not the net the row prints.',
            );

            $seen[] = round((float) $row[$net], 2);
        }

        sort($nets);
        sort($seen);
        $this->assertSame($nets, $seen, 'The sheet does not describe the payslips the run produced.');

        unlink($path);
    }

    /** The absence has to be somewhere on the row, not quietly netted off basic pay. */
    public function test_the_absence_is_charged_on_the_deductions_side(): void
    {
        $run = $this->runCycle();

        ['path' => $path] = app(PayrollService::class)->runXlsx($run);
        $rows = $this->sheets($path)['Payroll Export'];
        $head = $this->headerRow($rows);
        $header = $rows[$head];

        $basic = $this->columnIndex($header, 'Basic Pay');
        $deductions = $this->columnIndex($header, 'Total Deductions');

        $absent = $run->payslips()->where('absence_deduction', '>', 0)->firstOrFail();
        $row = null;

        foreach (array_slice($rows, $head + 1, $run->payslips()->count()) as $candidate) {
            if (round((float) $candidate[$basic], 2) === round((float) $absent->period_base, 2)
                && round((float) $candidate[$basic], 2) !== round((float) $absent->base_salary, 2)) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row, 'No row shows the FULL period basic for the employee who was absent.');
        $this->assertSame(
            round((float) $row[$deductions], 2),
            round(-((float) $absent->total_deductions + (float) $absent->absence_deduction), 2),
            'The absence charge is missing from the deductions total.',
        );

        unlink($path);
    }

    /** The TOTAL row must sum the rows above it, and land on the run's own net. */
    public function test_the_total_row_sums_the_sheet(): void
    {
        $run = $this->runCycle();

        ['path' => $path] = app(PayrollService::class)->runXlsx($run);
        $rows = $this->sheets($path)['Payroll Export'];

        $head = $this->headerRow($rows);
        $net = $this->columnIndex($rows[$head], 'Net Pay');
        $count = $run->payslips()->count();

        $body = array_slice($rows, $head + 1, $count);
        $total = $rows[$head + $count + 1];

        $this->assertSame('TOTAL PAYROLL', $total[0]);
        $this->assertSame(
            round(array_sum(array_column($body, $net)), 2),
            round((float) $total[$net], 2),
        );
        $this->assertSame(round((float) $run->total_net, 2), round((float) $total[$net], 2));

        unlink($path);
    }

    /**
     * In its XLSX shape. A name opening with `=` is a FORMULA to Excel — OpenSpout's own
     * `Cell::fromValue()` would hand it over as one. The writer must have printed it.
     */
    public function test_a_name_that_looks_like_a_formula_is_written_as_text(): void
    {
        ['path' => $path] = app(PayrollService::class)->runXlsx($this->runCycle());

        $names = array_column($this->sheets($path)['Payroll Export'], 0);
        $this->assertContains('=cmd|calc', $names, 'The name was not written verbatim as text.');

        $xml = file_get_contents('zip://' . $path . '#xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f>', $xml, 'The sheet contains a formula cell.');

        unlink($path);
    }
}
