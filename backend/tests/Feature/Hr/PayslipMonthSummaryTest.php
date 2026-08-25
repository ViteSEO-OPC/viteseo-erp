<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/** THE MONTH ROLL-UP the redesigned payslip carries on its last cutoff. */
class PayslipMonthSummaryTest extends HrTestCase
{
    use RefreshDatabase;

    private const FIRST = ['2026-03-01', '2026-03-15'];

    private const SECOND = ['2026-03-16', '2026-03-31'];

    private Employee $employee;

    private User $employeeUser;

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
        $this->employeeUser = User::factory()->create();
        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            'tin' => '123-456-789-000', 'sss_number' => '34-5678901-2',
            'philhealth_number' => '12-009876543-1', 'pagibig_number' => '1211-0098-7654',
        ]);
    }

    /** Attendance across a cycle, minus the first `$absentDays` workdays of it. */
    private function attendance(string $start, string $end, int $absentDays = 0): void
    {
        $tz = config('app.timezone_local');
        $last = Carbon::parse($end, $tz);
        $skipped = 0;

        for ($d = Carbon::parse($start, $tz); $d->lte($last); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }

            if ($skipped < $absentDays) {
                $skipped++;

                continue;
            }

            Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function runCycle(array $cycle): Payslip
    {
        app(PayrollService::class)->run(
            $cycle[0], $cycle[1], 'semi_monthly', User::factory()->create(),
        );

        return Payslip::where('employee_id', $this->employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('cycle_start', $cycle[0]))
            ->firstOrFail();
    }

    private function document(Payslip $payslip): array
    {
        Sanctum::actingAs($this->employeeUser);

        return $this->getJson("/api/v1/hr/me/payslips/{$payslip->id}")
            ->assertOk()
            ->json('data');
    }

    /** Move the three timing switches together, the way HR sets them in Company → Settings. */
    private function timing(string $value): void
    {
        PayrollSetting::effectiveFrom('2000-01-01');
        PayrollSetting::current()->update([
            'deduction_timing' => $value,
            'tax_timing' => $value,
            'allowance_timing' => $value,
        ]);
    }

    /**
     * The four-step walk the redesigned document prints, on a single slip.
     *
     * Asserted against the SERVED payload rather than recomputed from the columns, so an error in
     * `documentTotals()` cannot be mirrored here.
     */
    private function assertWalkCloses(array $doc): void
    {
        $t = $doc['totals'];

        $this->assertEqualsWithDelta($t['current'], $t['earnings'] - $t['deductions'], 0.01,
            'Total Current Amount is not earnings less deductions.');
        $this->assertEqualsWithDelta($t['initial_net'], $t['current'] - $t['government'], 0.01,
            'Initial Net Salary is not the current amount less contributions.');
        $this->assertEqualsWithDelta($doc['net_pay'], $t['initial_net'] + $t['allowances'], 0.01,
            'Net pay is not the initial net plus allowances.');
        // The pre-existing identity must survive the re-cut.
        $this->assertEqualsWithDelta($doc['net_pay'], $t['compensation'] - $t['deductions'] - $t['government'], 0.01,
            'The original compensation identity no longer closes.');
    }

    public function test_the_first_cutoff_is_labelled_and_carries_no_month_summary(): void
    {
        $this->attendance(...self::FIRST);
        $doc = $this->document($this->runCycle(self::FIRST));

        $this->assertSame(0, $doc['cutoff']['index']);
        $this->assertSame(2, $doc['cutoff']['count']);
        $this->assertFalse($doc['cutoff']['is_last']);
        $this->assertSame('1st Cut-Off', $doc['cutoff']['label']);
        // Nothing to restate — the summary would be a second copy of the figures above it.
        $this->assertNull($doc['month_summary']);
        $this->assertWalkCloses($doc);
    }

    public function test_the_last_cutoff_rolls_the_month_up_and_lands_on_its_own_net_pay(): void
    {
        $this->timing('last');
        $this->attendance(...self::FIRST);
        $this->attendance(...self::SECOND);

        $first = $this->document($this->runCycle(self::FIRST));
        $second = $this->document($this->runCycle(self::SECOND));

        $this->assertSame('2nd Cut-Off', $second['cutoff']['label']);
        $this->assertTrue($second['cutoff']['is_last']);

        $m = $second['month_summary'];
        $this->assertNotNull($m, 'The last cutoff of a two-cutoff month must carry the roll-up.');
        $this->assertCount(2, $m['periods']);
        $this->assertSame('1st Cut-Off', $m['periods'][0]['label']);
        $this->assertFalse($m['periods'][0]['is_current']);
        $this->assertTrue($m['periods'][1]['is_current']);

        // The first cutoff is restated by its Total Current Amount, not by its net — the two are
        // equal only when nothing was deducted on it, which is exactly the case this must not
        // silently depend on.
        $this->assertEqualsWithDelta(
            $first['totals']['current'], $m['periods'][0]['current_amount'], 0.01,
        );

        $this->assertEqualsWithDelta($m['initial_net'], $m['total_current'] - $m['government'], 0.01);
        $this->assertEqualsWithDelta($m['total_month'], $m['initial_net'] + $m['allowances'], 0.01);
        $this->assertEqualsWithDelta($m['already_paid'], $first['net_pay'], 0.01);

        // The check with teeth: the month, computed from every slip, minus what the earlier slips
        // paid, is this slip's own stored net. Two directions, one answer.
        $this->assertEqualsWithDelta($second['net_pay'], $m['total_month'] - $m['already_paid'], 0.01);
        $this->assertEqualsWithDelta($m['total_month'], $first['net_pay'] + $second['net_pay'], 0.01);
    }

    /** THE CASE THE MOCKUP'S ARITHMETIC WOULD HAVE FAILED. */
    public function test_the_roll_up_holds_when_deductions_are_split_across_both_cutoffs(): void
    {
        $this->timing('every');
        $this->attendance(...self::FIRST);
        $this->attendance(...self::SECOND);

        $first = $this->document($this->runCycle(self::FIRST));
        $second = $this->document($this->runCycle(self::SECOND));

        // The premise: the first cutoff really did take contributions of its own.
        $this->assertGreaterThan(0.0, $first['totals']['government']);

        $m = $second['month_summary'];
        $this->assertEqualsWithDelta(
            $first['totals']['government'] + $second['totals']['government'],
            $m['government'],
            0.01,
            'The month roll-up ignored the contributions taken on the earlier cutoff.',
        );
        $this->assertEqualsWithDelta($second['net_pay'], $m['total_month'] - $m['already_paid'], 0.01);
        $this->assertEqualsWithDelta($m['total_month'], $first['net_pay'] + $second['net_pay'], 0.01);
    }

    /** A monthly-paid employee has no cutoffs to be first among, and no month to restate. */
    public function test_a_monthly_employee_has_one_cutoff_and_no_summary(): void
    {
        $this->employee->update(['pay_frequency' => 'monthly']);
        $this->attendance('2026-03-01', '2026-03-31');

        app(PayrollService::class)->run(
            '2026-03-01', '2026-03-31', 'monthly', User::factory()->create(),
        );
        $payslip = Payslip::where('employee_id', $this->employee->id)->firstOrFail();
        $doc = $this->document($payslip);

        $this->assertSame(1, $doc['cutoff']['count']);
        $this->assertNull($doc['cutoff']['label']);
        $this->assertNull($doc['month_summary']);
        $this->assertWalkCloses($doc);
    }

    /** The Deductions table's rows must sum to the total printed beneath them. */
    public function test_deduction_rows_sum_to_the_deduction_total(): void
    {
        $this->attendance(self::FIRST[0], self::FIRST[1], absentDays: 3);
        $doc = $this->document($this->runCycle(self::FIRST));

        $rows = $doc['deductions_breakdown'];
        $this->assertNotEmpty($rows);

        $this->assertEqualsWithDelta(
            $doc['totals']['deductions'],
            array_sum(array_column($rows, 'amount')),
            0.01,
            'The deduction rows do not add up to the deduction total.',
        );

        // The five skeleton rows are always present, even at zero — a missing "Undertime" row is
        // indistinguishable from an undertime charge that was silently dropped.
        $keys = array_column($rows, 'key');
        foreach (['absent', 'undertime', 'half_day', 'late', 'suspended_unpaid'] as $expected) {
            $this->assertContains($expected, $keys);
        }

        // The absences row carries its dates, which is what the document prints beneath it.
        $absences = collect($rows)->firstWhere('key', 'absent');
        $this->assertSame(3, $absences['count']);
        $this->assertCount(3, $absences['dates']);
        $this->assertGreaterThan(0.0, $absences['amount']);
    }

    /** Even a period whose absences wipe out basic pay must still foot. */
    public function test_deduction_rows_still_foot_when_basic_pay_clamps_at_zero(): void
    {
        // No attendance at all: every workday in the period is unpaid.
        $doc = $this->document($this->runCycle(self::FIRST));

        $this->assertEqualsWithDelta(
            $doc['totals']['deductions'],
            array_sum(array_column($doc['deductions_breakdown'], 'amount')),
            0.01,
        );
        $this->assertWalkCloses($doc);
    }

    /** A PAYSLIP FROM BEFORE THE CHARGE LEDGER EXISTED must still foot. */
    public function test_a_payslip_with_no_charge_ledger_still_attributes_its_absence_charge(): void
    {
        $this->attendance(self::FIRST[0], self::FIRST[1], absentDays: 3);
        $payslip = $this->runCycle(self::FIRST);

        // Exactly the state an older payslip is in: the charge, with nothing explaining it.
        $payslip->forceFill(['attendance_lines' => null])->save();
        $doc = $this->document($payslip);

        $this->assertGreaterThan(0.0, $doc['totals']['deductions']);
        $this->assertEqualsWithDelta(
            $doc['totals']['deductions'],
            array_sum(array_column($doc['deductions_breakdown'], 'amount')),
            0.01,
            'A payslip with no charge ledger printed deduction rows that do not add up.',
        );

        // The charge lands on Absences, with no dates to show for it — which is the honest
        // rendering, rather than an invented ledger.
        $absences = collect($doc['deductions_breakdown'])->firstWhere('key', 'absent');
        $this->assertGreaterThan(0.0, $absences['amount']);
        $this->assertSame(0, $absences['count']);
    }

    /** THE PDF IS STILL ONE PAGE on the tallest payslip the redesign can produce. */
    public function test_the_last_cutoff_pdf_is_a_single_page_at_absurd_content(): void
    {
        $this->attendance(...self::FIRST);
        $this->attendance(...self::SECOND, absentDays: 3);
        $this->runCycle(self::FIRST);
        $payslip = $this->runCycle(self::SECOND);

        // Not saved: the roll-up reads the stored rows, so this inflates only what THIS document
        // renders — which is exactly the height being measured.
        $long = str_repeat('Extremely Long Programme Name ', 4);
        $payslip->allowances_breakdown = collect(range(1, 30))
            ->map(fn ($i) => ['name' => $long.$i, 'amount' => 1500])->all();
        $payslip->other_deductions_breakdown = collect(range(1, 30))
            ->map(fn ($i) => ['name' => $long.'D'.$i, 'amount' => 500])->all();
        $payslip->holiday_premium = 1200;
        $payslip->overtime_pay = 3400;
        $payslip->night_diff_pay = 900;
        $payslip->rest_day_premium = 1100;
        $payslip->reimbursements = 2500;
        $payslip->adjustment_pay = 1500;
        $payslip->deduction_carryover = 1234.56;
        $payslip->deduction_carryover_breakdown = [
            'sss' => 500, 'philhealth' => 234.56, 'pagibig' => 200,
            'withholding_tax' => 200, 'other_deductions' => 100,
        ];
        // The year-end settlement block, which only December would otherwise produce.
        $payslip->tax_annualisation_basis = [
            'year' => 2026, 'year_taxable' => 480000, 'annual_tax_due' => 62500,
            'previously_withheld' => 67932.12, 'settled_this_period' => -5432.12,
        ];

        $pdf = app(PayrollService::class)->payslipPdf($payslip)->output();

        // DomPDF writes one `/Type /Page` object per page; the catalogue's is `/Type /Pages`,
        // which the trailing character class excludes.
        $this->assertSame(
            1,
            preg_match_all('#/Type\s*/Page[^s]#', $pdf),
            'the last cutoff must never run to a second page',
        );
    }

    /** The document the PDF renders is the one the screen shows — same tables, same figures. */
    public function test_the_pdf_carries_the_month_roll_up(): void
    {
        $this->attendance(...self::FIRST);
        $this->attendance(...self::SECOND);
        $this->runCycle(self::FIRST);
        $payslip = $this->runCycle(self::SECOND);

        $data = app(PayrollService::class)->payslipViewData($payslip);
        $html = view('hr::payslip', $data)->render();

        $this->assertNotNull($data['p']['month_summary']);
        $this->assertStringContainsString('Salary Summary', $html);
        $this->assertStringContainsString('Total Month Salary', $html);
        $this->assertStringContainsString('Total Net (2nd Cut-Off)', $html);
        $this->assertStringContainsString('Total Current Amount', $html);
        $this->assertStringContainsString('Initial Net Salary', $html);
        $this->assertStringContainsString('Non-Taxable Allowance', $html);

        // The workings were removed from both surfaces on 2026-08-14 — the PDF must not keep them.
        $this->assertStringNotContainsString('Computation Basis', $html);
        $this->assertStringNotContainsString('the maximum this contribution is charged on', $html);
        // Nor the identity block the same change dropped.
        $this->assertStringNotContainsString('Pag-IBIG #', $html);
        $this->assertStringNotContainsString('>TIN<', $html);
    }

    /** The header states the daily rate three ways; the hourly one follows the run's own divisor. */
    public function test_the_header_rates_derive_from_the_stored_daily_rate(): void
    {
        $this->attendance(...self::FIRST);
        $doc = $this->document($this->runCycle(self::FIRST));

        $rates = $doc['rates'];
        $this->assertGreaterThan(0.0, $rates['daily']); // the assertions below must have teeth
        $this->assertEqualsWithDelta($doc['daily_rate'], $rates['daily'], 0.01);
        $this->assertEqualsWithDelta($doc['daily_rate'] / 2, $rates['half_day'], 0.01);
        // The seeded schedule is an 8-hour day, which is the divisor the charge arithmetic uses.
        $this->assertEqualsWithDelta($doc['daily_rate'] / 8, $rates['hourly'], 0.01);
    }
}
