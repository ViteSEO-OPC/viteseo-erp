<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Http\Resources\PayslipResource;
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

/** A payslip that explains its own gross, for every run type. */
class PayslipEarningsLinesTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-12-20 09:00', config('app.timezone_local')));

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
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->workDecember();
    }

    /**
     * Present every December workday. Without attendance the regular run pays no basic at all, which
     * makes the 13th-month basis zero and writes no 13th-month payslip to inspect — the fixture would
     * silently test nothing.
     */
    private function workDecember(): void
    {
        $tz = config('app.timezone_local');

        for ($day = Carbon::parse('2026-12-01', $tz); $day->month === 12; $day->addDay()) {
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

    /** @return array<string, mixed> */
    private function serialise(Payslip $slip): array
    {
        return (new PayslipResource($slip->load('payrollRun', 'employee.user', 'employee.department')))
            ->toArray(request());
    }

    /** @param array<string, mixed> $data */
    private function payslipOn(PayrollRun $run): Payslip
    {
        return Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();
    }

    private function names(array $serialised): array
    {
        return array_column($serialised['earnings_breakdown'], 'name');
    }

    /** The payslip PDF template rendered as searchable HTML, through the real view-data path. */
    private function renderPayslip(Payslip $slip): string
    {
        return view('hr::payslip', app(PayrollService::class)->payslipViewData($slip))->render();
    }

    private function lineTotal(array $serialised): float
    {
        return round(array_sum(array_column($serialised['earnings_breakdown'], 'amount')), 2);
    }

    // --- the four run types ---------------------------------------------------------------------

    public function test_a_regular_payslip_leads_with_basic_pay(): void
    {
        $run = app(PayrollService::class)->run('2026-12-01', '2026-12-15', 'semi_monthly', $this->hrUser());

        $serialised = $this->serialise($this->payslipOn($run));

        $this->assertSame('regular', $serialised['run_type']);
        $this->assertSame('Basic Pay', $this->names($serialised)[0]);
        $this->assertSame('Semi-monthly', $serialised['run_type_label']);
    }

    /**
     * THE ORIGINAL COMPLAINT. Before this the slip showed a gross, a tax figure and every earnings
     * line at ₱0.
     */
    public function test_a_thirteenth_month_payslip_names_its_own_earning(): void
    {
        // A completed regular run gives the 13th-month basis something to read.
        app(PayrollService::class)->run('2026-12-01', '2026-12-15', 'semi_monthly', $this->hrUser());

        $run = app(PayrollService::class)->runThirteenthMonth(2026, $this->hrUser());
        $serialised = $this->serialise($this->payslipOn($run));

        $this->assertSame('thirteenth_month', $serialised['run_type']);
        $this->assertContains('13th Month Pay', $this->names($serialised));
        $this->assertNotContains('Basic Pay', $this->names($serialised), 'it paid no basic salary');
        $this->assertGreaterThan(0, $serialised['thirteenth_month'], 'and the figure is serialised');
    }

    /** `other_benefits` is the same money seen from the tax side — it must not be a second line. */
    public function test_the_thirteenth_month_is_not_counted_twice(): void
    {
        app(PayrollService::class)->run('2026-12-01', '2026-12-15', 'semi_monthly', $this->hrUser());
        $run = app(PayrollService::class)->runThirteenthMonth(2026, $this->hrUser());

        $serialised = $this->serialise($this->payslipOn($run));

        $this->assertSame(
            round((float) $serialised['thirteenth_month'], 2),
            $this->lineTotal($serialised),
            'the only earning is the 13th month itself',
        );
    }

    public function test_an_adjustment_payslip_names_the_adjustment(): void
    {
        $run = app(PayrollService::class)->runAdjustment(
            '2026-11-01',
            '2026-11-30',
            'Missed overtime for October',
            [['employee_id' => $this->employee->id, 'amount' => 2500]],
            $this->hrUser(),
        );

        $serialised = $this->serialise($this->payslipOn($run));

        $this->assertSame('adjustment', $serialised['run_type']);
        $this->assertSame('Adjustment', $serialised['run_type_label']);
        $this->assertContains('Adjustment', $this->names($serialised));
        $this->assertSame(2500.0, $this->lineTotal($serialised));
    }

    /** The reason travels onto the document — a gross that came from a person must say so. */
    public function test_the_adjustment_line_carries_its_reason(): void
    {
        $run = app(PayrollService::class)->runAdjustment(
            '2026-11-01',
            '2026-11-30',
            'Missed overtime for October',
            [[
                'employee_id' => $this->employee->id,
                'amount' => 2500,
                'note' => 'October overtime, approved late',
            ]],
            $this->hrUser(),
        );

        $line = collect($this->serialise($this->payslipOn($run))['earnings_breakdown'])
            ->firstWhere('name', 'Adjustment');

        $this->assertSame('October overtime, approved late', $line['note']);
    }

    public function test_a_final_pay_payslip_names_its_terminal_lines(): void
    {
        $this->employee->update(['separation_type' => 'voluntary', 'separation_date' => '2026-12-15']);

        $run = app(PayrollService::class)->runFinalPay(
            $this->employee->fresh(),
            ['reason' => 'Resignation', 'separation_pay' => 45000],
            $this->hrUser(),
        );

        $serialised = $this->serialise($this->payslipOn($run));

        $this->assertSame('final_pay', $serialised['run_type']);
        $this->assertContains('Separation Pay', $this->names($serialised));
        $this->assertSame(45000.0, $this->lineTotal($serialised));
    }

    /**
     * `frequency` on a final-pay run is "final_pay:<employee id>" — an internal key with a database
     * id in it, and the header was printing it onto a document handed to the employee.
     */
    public function test_the_header_never_leaks_the_internal_frequency_key(): void
    {
        $this->employee->update(['separation_type' => 'voluntary', 'separation_date' => '2026-12-15']);

        $run = app(PayrollService::class)->runFinalPay(
            $this->employee->fresh(),
            ['reason' => 'Resignation', 'separation_pay' => 10000],
            $this->hrUser(),
        );

        $serialised = $this->serialise($this->payslipOn($run));

        $this->assertSame('Final Pay', $serialised['run_type_label']);
        $this->assertStringNotContainsString('final_pay:', $serialised['run_type_label']);
        $this->assertStringNotContainsString(
            (string) $this->employee->id,
            $serialised['run_type_label'],
            'no employee id on the document',
        );
    }

    // --- the invariant --------------------------------------------------------------------------

    /**
     * THE ONE THAT MAKES THE DOCUMENT HONEST. Whatever the lines say must add up to the figure printed
     * as Total Compensation, on every run type — otherwise the payslip shows an unexplained number,
     * which is the entire finding.
     */
    public function test_the_lines_always_sum_to_total_compensation(): void
    {
        $payroll = app(PayrollService::class);
        $slips = [];

        $slips['regular'] = $this->payslipOn(
            $payroll->run('2026-12-01', '2026-12-15', 'semi_monthly', $this->hrUser()),
        );
        $slips['thirteenth_month'] = $this->payslipOn($payroll->runThirteenthMonth(2026, $this->hrUser()));
        $slips['adjustment'] = $this->payslipOn($payroll->runAdjustment(
            '2026-11-01',
            '2026-11-30',
            'Correction',
            [['employee_id' => $this->employee->id, 'amount' => 1234.56]],
            $this->hrUser(),
        ));

        $this->employee->update(['separation_type' => 'voluntary', 'separation_date' => '2026-12-16']);
        $slips['final_pay'] = $this->payslipOn($payroll->runFinalPay(
            $this->employee->fresh(),
            ['reason' => 'Resignation', 'separation_pay' => 20000],
            $this->hrUser(),
        ));

        foreach ($slips as $type => $slip) {
            $serialised = $this->serialise($slip);

            $this->assertSame(
                (float) $serialised['totals']['compensation'],
                $this->lineTotal($serialised),
                "the named lines must explain the whole gross on a {$type} payslip",
            );
        }
    }

    /**
     * A regular period that genuinely paid no basic still shows the row — silently dropping the line
     * the whole document is built around would hide a full month of unpaid leave.
     */
    public function test_a_regular_payslip_keeps_basic_pay_even_at_zero(): void
    {
        // Strip the fixture's attendance, so every day of the period is unpaid.
        Attendance::query()->delete();

        $run = app(PayrollService::class)->run('2026-12-01', '2026-12-15', 'semi_monthly', $this->hrUser());
        $serialised = $this->serialise($this->payslipOn($run));

        $this->assertContains('Basic Pay', $this->names($serialised));
    }

    // --- the rendered PDF -----------------------------------------------------------------------

    /**
     * The finding's own Validation step: render the blade for a 13th-month slip and assert the gross
     * is explained by a named line.
     */
    public function test_the_pdf_template_renders_a_named_line_for_the_thirteenth_month(): void
    {
        app(PayrollService::class)->run('2026-12-01', '2026-12-15', 'semi_monthly', $this->hrUser());
        $run = app(PayrollService::class)->runThirteenthMonth(2026, $this->hrUser());

        $serialised = $this->serialise($this->payslipOn($run));

        // `payslipViewData()` exists precisely so a test can render the document as HTML — the PDF's
        // text is compressed and unsearchable. Using it also means this exercises the real path, not a
        // hand-assembled approximation of it.
        $html = $this->renderPayslip($this->payslipOn($run));

        $this->assertStringContainsString('13th Month Pay', $html);
        $this->assertStringContainsString('Total Compensation', $html);
        $this->assertStringNotContainsString('Basic Pay', $html, 'no ₱0 basic on a 13th-month slip');
    }

    public function test_the_pdf_header_shows_the_run_type(): void
    {
        app(PayrollService::class)->run('2026-12-01', '2026-12-15', 'semi_monthly', $this->hrUser());
        $run = app(PayrollService::class)->runThirteenthMonth(2026, $this->hrUser());

        $html = $this->renderPayslip($this->payslipOn($run));

        $this->assertStringContainsString('13th Month Pay', $html);
        $this->assertStringNotContainsString('thirteenth_month', $html, 'not the raw key');
    }
}
