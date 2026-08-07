<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The payslip DOCUMENT — the redesign (2026-07-28), not the payroll engine.
 *
 * The engine is covered elsewhere and none of its figures changed here. What changed is how
 * the document derives itself from them, and that derivation has one property worth pinning:
 *
 *     compensation − deductions − government = net_pay
 *
 * It has to hold on EVERY payslip, because a payslip whose arithmetic doesn't close is a
 * payslip an employee is entitled to dispute. The two ways it can break are the interesting
 * cases and both are tested: basic pay clamping at zero (absences worth more than the period),
 * and deductions exceeding gross.
 *
 * `+ uncollected` USED TO BE PART OF THIS IDENTITY (removed with FIN-053). The component columns
 * once stored the full computed deduction while the total was capped at gross, so the document
 * had to credit the shortfall back to avoid resolving to a large negative against a net of zero.
 * The columns now store what was collected, so the correction is gone at the source. `uncollected`
 * is still asserted below — it must still be REPORTED — but it must no longer be ADDED, and the
 * shortfall test guards exactly that: adding it back now would overstate net by the carryover.
 *
 * Also guarded: the employer's counterpart contributions ARE disclosed to the employee
 * (reversed 2026-08-07) but are never a term in any total — and the document is one page,
 * whatever the configuration throws at it.
 */
class PayslipDocumentTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    private Employee $employee;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Real contribution brackets, so the statutory rows (and the employer counterparts the
        // download must omit) carry actual amounts rather than zeroes.
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
            // Allowances so the breakdown has something to split, and government numbers so the
            // identity block isn't all em dashes.
            'tin' => '123-456-789-000', 'sss_number' => '34-5678901-2',
            'philhealth_number' => '12-009876543-1', 'pagibig_number' => '1211-0098-7654',
        ]);
    }

    /**
     * Attendance for the cycle, minus `$absentDays` of it. Without a record a day is unpaid,
     * so "worked every day" is what makes a full-pay payslip and skipping days is how an
     * absence is created.
     */
    private function attendance(int $absentDays = 0): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);
        $skipped = 0;

        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end); $d->addDay()) {
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

    private function runPayroll(): Payslip
    {
        app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );

        return Payslip::where('employee_id', $this->employee->id)->firstOrFail();
    }

    /** The document as the employee's own API returns it. */
    private function employeeDocument(Payslip $payslip): array
    {
        Sanctum::actingAs($this->employeeUser);

        return $this->getJson("/api/v1/hr/me/payslips/{$payslip->id}")
            ->assertOk()
            ->json('data');
    }

    /**
     * The identity the whole document rests on. Asserted against the SERVED payload rather
     * than recomputed here, so a mistake in `documentTotals()` can't be mirrored by the test.
     */
    private function assertReconciles(array $doc): void
    {
        $t = $doc['totals'];

        $this->assertEqualsWithDelta(
            $doc['net_pay'],
            $t['compensation'] - $t['deductions'] - $t['government'],
            0.01,
            'The payslip does not add up to its own net pay.',
        );
    }

    public function test_a_normal_payslip_reconciles_to_net_pay(): void
    {
        $this->attendance();
        $doc = $this->employeeDocument($this->runPayroll());

        $this->assertGreaterThan(0.0, $doc['net_pay']); // the assertion below must have teeth
        $this->assertReconciles($doc);
    }

    public function test_a_payslip_with_absences_reconciles_to_net_pay(): void
    {
        $this->attendance(absentDays: 3);
        $doc = $this->employeeDocument($this->runPayroll());

        $this->assertGreaterThan(0.0, $doc['absence_deduction']);
        $this->assertReconciles($doc);
    }

    /**
     * The point of the redesign: basic pay is shown WHOLE and the absence charged against it,
     * so an employee can see what a missed day cost. The two must still land on the stored
     * figure payroll actually paid.
     */
    public function test_basic_pay_less_the_absence_charge_is_the_stored_base_salary(): void
    {
        $this->attendance(absentDays: 2);
        $doc = $this->employeeDocument($this->runPayroll());

        // 20 000/mo semi-monthly ⇒ the full period basic, before any absence.
        $this->assertEqualsWithDelta(10000.0, $doc['period_base'], 0.01);
        $this->assertEqualsWithDelta(
            $doc['base_salary'],
            $doc['period_base'] - $doc['absence_deduction'],
            0.01,
        );
    }

    /**
     * When absences are worth more than the period, basic pay clamps at zero and the INTENDED
     * charge (unpaid days × daily rate) exceeds the one actually applied. Storing the applied
     * charge is the only way the document can still close — deriving it would subtract more
     * than was ever taken.
     */
    public function test_the_absence_charge_never_exceeds_the_period_basic(): void
    {
        // ₱2,400/day against a ₱10,000 period: three absences already overshoot.
        $policy = new AttendancePolicy(array_merge(AttendancePolicyService::DEFAULTS, [
            'daily_rate_basis' => 'fixed_divisor', 'annual_divisor' => 100,
        ]));

        $slip = app(PayrollService::class)->payslipFor(
            $this->employee, 0.0, 8.0, 24, 11, ['index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every'],
            0.0, null, $policy,
        );

        $this->assertSame(0.0, $slip['base_salary']);
        $this->assertEqualsWithDelta(10000.0, $slip['absence_deduction'], 0.01);
        $this->assertLessThan(8.0 * $slip['daily_rate'], $slip['absence_deduction']);
    }

    /**
     * Deductions bigger than pay. The cap is now allocated across the individual obligations
     * (FIN-053), so every figure the document shows is what was actually collected and the
     * identity closes on its own. The shortfall is still disclosed, and itemised by obligation
     * so the employee can see WHICH deduction went unpaid rather than one opaque total.
     */
    public function test_a_payslip_whose_deductions_exceed_pay_still_reconciles(): void
    {
        $program = BenefitProgram::create([
            'name' => 'Emergency Loan', 'category' => 'other', 'kind' => 'deduction',
            'deduction_cadence' => 'per_cutoff',
        ]);
        $this->employee->benefitPrograms()->attach($program->id, ['employee_share' => 50000]);

        $this->attendance();
        $doc = $this->employeeDocument($this->runPayroll());

        $this->assertEqualsWithDelta(0.0, $doc['net_pay'], 0.01);
        $this->assertGreaterThan(0.0, $doc['totals']['uncollected']);
        $this->assertReconciles($doc);

        // FIN-053 — the shortfall says which obligation it belongs to. The ₱50,000 benefit share
        // is last in the priority order, so it is what goes unpaid.
        $this->assertArrayHasKey('other_deductions', $doc['totals']['uncollected_breakdown']);
        $this->assertEqualsWithDelta(
            $doc['totals']['uncollected'],
            array_sum($doc['totals']['uncollected_breakdown']),
            0.01,
            'the itemised shortfall must account for the whole carryover',
        );
    }

    /** Enrol the employee in an allowance programme — the Slice B1 source of truth. */
    private function enrolInAllowance(string $name, float $monthly): void
    {
        $program = BenefitProgram::create([
            'name' => $name, 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => $monthly, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);
        $this->employee->benefitPrograms()->attach($program->id);
    }

    public function test_the_allowance_breakdown_sums_to_the_allowances_paid(): void
    {
        // Slice B1 — allowances come from Company → Benefits now, not the employee's columns.
        $this->enrolInAllowance('Transportation Allowance', 1000);
        $this->enrolInAllowance('Food Allowance', 500);

        $this->attendance();
        $doc = $this->employeeDocument($this->runPayroll());

        $lines = $doc['allowances_breakdown'];
        // Ordered by programme name (Slice B1) rather than the old hard-coded column order.
        $this->assertSame(
            ['Food Allowance', 'Transportation Allowance'],
            array_column($lines, 'name'),
        );
        $this->assertEqualsWithDelta($doc['allowances'], array_sum(array_column($lines, 'amount')), 0.01);
    }

    /**
     * Payslips written before the snapshot column existed can't be split after the fact — the
     * employee's allowance columns have moved on. One honest line beats a guess.
     */
    public function test_a_payslip_without_a_recorded_split_shows_a_single_allowance_line(): void
    {
        $this->enrolInAllowance('Transportation Allowance', 1000);
        $this->enrolInAllowance('Food Allowance', 500);

        $this->attendance();
        $payslip = $this->runPayroll();
        $payslip->update(['allowances_breakdown' => null]);

        $doc = $this->employeeDocument($payslip);

        $this->assertSame(
            [['name' => 'Allowances', 'amount' => $doc['allowances']]],
            $doc['allowances_breakdown'],
        );
    }

    /** The identity block is what makes the document usable as proof of income. */
    public function test_the_document_carries_the_employee_identity_block(): void
    {
        $this->attendance();
        $doc = $this->employeeDocument($this->runPayroll());

        $this->assertSame('E-001', $doc['employee']['employee_no']);
        $this->assertSame('Ops', $doc['employee']['department']);
        $this->assertSame('123-456-789-000', $doc['employee']['tin']);
        $this->assertNotNull($doc['pay_date']);
        $this->assertSame('semi_monthly', $doc['frequency']);
    }

    /**
     * The employee DOES see the employer's counterpart contributions (2026-08-07, Joshua's call).
     *
     * THIS TEST USED TO ASSERT THE OPPOSITE, and the reasoning it carried was that the employer
     * share is remittance data and "not theirs to see". That was reconsidered: this money is paid
     * into the employee's OWN SSS, PhilHealth and Pag-IBIG record, and it is the part of their
     * compensation they are least likely to know exists. Withholding it protected nothing.
     *
     * What it must NOT do is change any figure — see the assertions below, which are the real
     * subject of this test. Disclosure only.
     */
    public function test_the_employee_sees_the_employer_share_as_disclosure_only(): void
    {
        $this->attendance();
        $doc = $this->employeeDocument($this->runPayroll());

        $this->assertArrayHasKey('employer_sss', $doc);
        $this->assertArrayHasKey('employer_philhealth', $doc);
        $this->assertArrayHasKey('employer_pagibig', $doc);
        $this->assertGreaterThan(0, $doc['employer_sss'], 'a real figure, not a placeholder');

        // NOT IN ANY TOTAL. The employer share is the company's cost; nothing was deducted for it,
        // so net pay must still be exactly gross minus what was actually taken from the employee.
        $this->assertSame(
            round((float) $doc['gross'] - (float) $doc['total_deductions'], 2),
            round((float) $doc['net_pay'], 2),
            'net pay must not move when the employer share is disclosed',
        );
        $this->assertGreaterThan(
            0,
            (float) $doc['employer_sss'] + (float) $doc['employer_philhealth'] + (float) $doc['employer_pagibig'],
        );
    }

    /**
     * The two things that ARE still HR-only, and the reason they differ from the employer share:
     * both are remittance workflow rather than disclosure. One says a filing cannot be made as it
     * stands; the other is what a remittance query asks for. Neither is actionable by the employee.
     */
    public function test_remittance_workflow_fields_stay_hr_only(): void
    {
        $this->attendance();
        $doc = $this->employeeDocument($this->runPayroll());

        $this->assertArrayNotHasKey('contribution_overrides', $doc);
        $this->assertArrayNotHasKey('contribution_bases', $doc);
    }

    public function test_hr_sees_the_employer_share_on_the_same_document(): void
    {
        $this->attendance();
        $payslip = $this->runPayroll();

        $doc = $this->actingAsHr()
            ->getJson("/api/v1/hr/payslips/{$payslip->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('employer_sss', $doc);
        $this->assertReconciles($doc);
    }

    public function test_an_employee_cannot_read_someone_elses_payslip(): void
    {
        $this->attendance();
        $payslip = $this->runPayroll();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/hr/me/payslips/{$payslip->id}")->assertNotFound();
    }

    public function test_the_pdf_renders_for_both_sides(): void
    {
        $this->attendance(absentDays: 1);
        $payslip = $this->runPayroll();

        $hr = $this->actingAsHr()->get("/api/v1/hr/payslips/{$payslip->id}/pdf");
        $hr->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $hr->getContent());

        Sanctum::actingAs($this->employeeUser);
        $this->get("/api/v1/hr/me/payslips/{$payslip->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The DOWNLOAD now carries the employer share — as disclosure, below the net-pay bar.
     *
     * REVERSED 2026-08-07 (Joshua's call). This asserted the opposite, on the grounds that a
     * payslip file gets forwarded — to a bank, a landlord, an embassy — so it should not carry
     * remittance data. The forwarding argument survives; the classification did not. The employer
     * share is money paid into the employee's own SSS, PhilHealth and Pag-IBIG record, so it
     * belongs on the document they receive.
     *
     * STILL ONE DOCUMENT FOR BOTH AUDIENCES, which is the property that actually mattered: HR's
     * copy and the employee's remain identical, because this is now in both.
     *
     * Asserted against the rendered HTML because the PDF's text is compressed.
     */
    public function test_the_downloaded_payslip_discloses_the_employer_share(): void
    {
        $this->attendance();
        $payslip = $this->runPayroll();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->hrUser());

        $data = app(PayrollService::class)->payslipViewData($payslip, $request);

        $this->assertArrayHasKey('employer_sss', $data['p']);
        $this->assertGreaterThan(0.0, $data['p']['employer_sss']);

        $html = view('hr::payslip', $data)->render();

        // It appears as a sub-line under each statutory row, not as a block of its own.
        $this->assertSame(3, substr_count($html, '+ employer share'), 'one under each of the three');
        $this->assertStringContainsString('Not deducted from your pay', $html);
        $this->assertStringContainsString('Total Net Pay', $html); // the render actually happened

        // THE ASSERTION THAT MATTERS: `Total Gov` is the employee's own contributions and tax,
        // and nothing else. Computed here from the payslip's columns rather than read off the
        // document, so a mistake in `documentTotals()` cannot be mirrored by the test.
        $employeeSide = round(
            (float) $payslip->sss + (float) $payslip->philhealth
                + (float) $payslip->pagibig + (float) $payslip->withholding_tax,
            2,
        );

        $this->assertSame($employeeSide, round((float) $data['p']['totals']['government'], 2));
        $this->assertStringContainsString(number_format($employeeSide, 2), $html);

        // And the employer figures really are non-zero, or the assertion above proves nothing.
        $this->assertGreaterThan(0.0, $data['p']['employer_sss']);

        // The employee's own render must be the SAME document, not a reduced one.
        $employeeRequest = Request::create('/');
        $employeeRequest->setUserResolver(fn () => $this->employee->user);

        $employeeHtml = view(
            'hr::payslip',
            app(PayrollService::class)->payslipViewData($payslip, $employeeRequest),
        )->render();

        $this->assertSame(3, substr_count($employeeHtml, '+ employer share'));
    }

    /**
     * THE PAYSLIP IS ONE PAGE, whatever the configuration throws at it.
     *
     * WHY THIS IS A TEST AND NOT A STYLE PREFERENCE. When the document overflows it does not lose
     * the top — it pushes the NET PAY and the signature block onto page two, which is the half the
     * employee and any recipient actually read. Measured on 2026-08-07: six allowance programmes
     * and six benefit deductions already did it, before anything was added.
     *
     * The height is BOUNDED rather than merely tuned: `$capped` rolls surplus breakdown lines into
     * one labelled summed line and `$fit`/`$fitWide` trim names that would wrap, so the page cannot
     * grow with data. This proves it at an absurd input — thirty of each, names long enough to wrap.
     */
    public function test_the_pdf_is_a_single_page_even_with_absurd_content(): void
    {
        $this->attendance();
        $payslip = $this->runPayroll();

        $long = str_repeat('Extremely Long Programme Name ', 4);
        $payslip->allowances_breakdown = collect(range(1, 30))
            ->map(fn ($i) => ['name' => $long . $i, 'amount' => 1500])->all();
        $payslip->other_deductions_breakdown = collect(range(1, 30))
            ->map(fn ($i) => ['name' => $long . 'D' . $i, 'amount' => 500])->all();
        $payslip->holiday_premium = 1200;
        $payslip->overtime_pay = 3400;
        $payslip->night_diff_pay = 900;
        $payslip->rest_day_premium = 1100;
        $payslip->overtime_minutes = 740;
        $payslip->night_diff_minutes = 420;
        $payslip->rest_day_minutes = 480;
        $payslip->unpaid_days = 2.5;
        $payslip->late_minutes = 95;
        $payslip->undertime_minutes = 60;
        $payslip->late_penalty_days = 1;
        $payslip->reimbursements = 2500;
        $payslip->adjustment_pay = 1500;
        $payslip->deduction_carryover = 1234.56;
        $payslip->deduction_carryover_breakdown = [
            'sss' => 500, 'philhealth' => 234.56, 'pagibig' => 200,
            'withholding_tax' => 200, 'other_deductions' => 100,
        ];

        $pdf = app(PayrollService::class)->payslipPdf($payslip)->output();

        // DomPDF writes one `/Type /Page` object per page; the catalogue's is `/Type /Pages`,
        // which the trailing character class excludes.
        $pages = preg_match_all('#/Type\s*/Page[^s]#', $pdf);

        $this->assertSame(1, $pages, 'the payslip must never run to a second page');
    }

    /** The roll-up SUMMARISES, it does not drop — every peso is still on the document. */
    public function test_the_one_page_roll_up_keeps_the_full_amount(): void
    {
        $this->attendance();
        $payslip = $this->runPayroll();

        $payslip->other_deductions_breakdown = collect(range(1, 20))
            ->map(fn ($i) => ['name' => 'Deduction ' . $i, 'amount' => 100])->all();

        $html = view('hr::payslip', app(PayrollService::class)->payslipViewData($payslip))->render();

        // 20 lines capped at 4 ⇒ 3 shown, then one rolled line covering the remaining 17.
        $this->assertStringContainsString('Other deductions (17 items)', $html);
        // 17 × ₱100 — the rolled line carries the sum, so the column still totals the same.
        $this->assertStringContainsString('1,700.00', $html);
    }

    /** A payslip with nothing recorded but the basics must not fall over on a null field. */
    public function test_the_pdf_renders_for_a_payslip_with_no_optional_figures(): void
    {
        $this->employee->update([
            'tin' => null, 'sss_number' => null, 'philhealth_number' => null, 'pagibig_number' => null,
            'address' => null,
        ]);
        $this->attendance();

        $payslip = $this->runPayroll();

        $this->actingAsHr()
            ->get("/api/v1/hr/payslips/{$payslip->id}/pdf")
            ->assertOk();
    }
}
