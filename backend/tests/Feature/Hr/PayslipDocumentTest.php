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
 *     compensation − deductions − government + uncollected = net_pay
 *
 * It has to hold on EVERY payslip, because a payslip whose arithmetic doesn't close is a
 * payslip an employee is entitled to dispute. The two ways it can break are the interesting
 * cases and both are tested: basic pay clamping at zero (absences worth more than the period),
 * and deductions exceeding gross (the carryover credit).
 *
 * Also guarded: the employer's counterpart contributions are remittance data, not the
 * employee's, and must not reach them.
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
            'transportation_allowance' => 1000, 'food_allowance' => 500, // legacy columns — no longer read (Slice B1)
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
            $t['compensation'] - $t['deductions'] - $t['government'] + $t['uncollected'],
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
     * Deductions bigger than pay. `total_deductions` is capped at gross, so without crediting
     * the uncollected remainder back the document would resolve to a large negative number
     * against a net pay of zero.
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
     * The employer's counterpart contributions are remittance data. They are not deducted from
     * the employee and are not theirs to see — this was previously served to everyone.
     */
    public function test_the_employer_share_is_withheld_from_the_employee(): void
    {
        $this->attendance();
        $doc = $this->employeeDocument($this->runPayroll());

        $this->assertArrayNotHasKey('employer_sss', $doc);
        $this->assertArrayNotHasKey('employer_philhealth', $doc);
        $this->assertArrayNotHasKey('employer_pagibig', $doc);
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
     * The DOWNLOAD carries no employer share, even for HR, even though HR's payload has it.
     *
     * A payslip file gets forwarded — to a bank, a landlord, an embassy — so HR's copy and the
     * employee's have to be the same document. The employer share is remittance data and stays
     * on HR's screen. Asserted against the rendered HTML because the PDF's text is compressed.
     */
    public function test_the_downloaded_payslip_omits_the_employer_share(): void
    {
        $this->attendance();
        $payslip = $this->runPayroll();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->hrUser());

        $data = app(PayrollService::class)->payslipViewData($payslip, $request);

        // The payload really does carry it — otherwise the assertion below proves nothing.
        $this->assertArrayHasKey('employer_sss', $data['p']);
        $this->assertGreaterThan(0.0, $data['p']['employer_sss']);

        $html = view('hr::payslip', $data)->render();

        $this->assertStringNotContainsString('Employer Share', $html);
        $this->assertStringNotContainsString('remittance', $html);
        $this->assertStringContainsString('Total Net Pay', $html); // the render actually happened
    }

    /** A payslip with nothing recorded but the basics must not fall over on a null field. */
    public function test_the_pdf_renders_for_a_payslip_with_no_optional_figures(): void
    {
        $this->employee->update([
            'transportation_allowance' => 0, 'food_allowance' => 0, 'load_allowance' => 0,
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
