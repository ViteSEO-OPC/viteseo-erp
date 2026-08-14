<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The payslip explaining its own deductions (FIN-055).
 *
 * Two claims, and one property that makes both worth trusting: the per-date ledger SUMS to the
 * unpaid-days figure printed beside it. A breakdown that does not reconcile with the number it
 * explains is worse than no breakdown, because it invites the reader to rely on it.
 */
class PayslipComputationBasisTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** A Mon–Fri fortnight, run after it has fully passed so absences bite. */
    private const CYCLE_START = '2026-07-01';

    private const CYCLE_END = '2026-07-15';

    private const NOW = '2026-07-20 09:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Day', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 15, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function present(string $date, string $in = '09:00', string $out = '18:00'): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$in}", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} {$out}", $tz)->utc(),
            'status' => $in > '09:15' ? 'late' : 'present',
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    /**
     * Turn on per-minute late/undertime charging.
     *
     * Lateness only reaches the payslip at all under one of these modes — `late_minutes` and the
     * ledger's `late` lines come from the same block, so a policy that charges nothing prints
     * neither. Tests about lateness therefore have to say which policy they are about.
     */
    private function chargeLateness(): void
    {
        app(AttendancePolicyService::class)->update([
            'late_deduction_mode' => 'per_hour',
            'undertime_deduction_mode' => 'per_hour',
        ]);
    }

    private function runAndFetch(): Payslip
    {
        $run = app(PayrollService::class)->run(
            self::CYCLE_START,
            self::CYCLE_END,
            'semi_monthly',
            $this->hrUser(),
        );

        return Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();
    }

    // --- the ledger ----------------------------------------------------------------------

    public function test_the_ledger_names_the_days_that_were_not_paid(): void
    {
        // Present every workday except the 8th and 9th.
        foreach (['01', '02', '03', '06', '07', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }

        $slip = $this->runAndFetch();
        $dates = collect($slip->attendance_lines)
            ->where('kind', 'absent')
            ->pluck('date')
            ->all();

        $this->assertSame(['2026-07-08', '2026-07-09'], $dates);
    }

    public function test_the_ledger_sums_to_the_unpaid_days_figure(): void
    {
        // The property the whole feature rests on. Deliberately a messy period: two absences
        // and a lateness, so the sum is not trivially the count of one kind.
        foreach (['01', '02', '03', '06', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }
        $this->present('2026-07-07', '11:30'); // late

        $slip = $this->runAndFetch();

        $this->assertEqualsWithDelta(
            (float) $slip->unpaid_days,
            array_sum(array_column($slip->attendance_lines, 'days')),
            0.0001,
            'A breakdown that does not reconcile with its own total is worse than none.',
        );
    }

    public function test_lateness_is_listed_with_its_minutes(): void
    {
        $this->chargeLateness();

        foreach (['01', '02', '03', '06', '08', '09', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }
        $this->present('2026-07-07', '10:30'); // 90 minutes past the 09:00 start

        $slip = $this->runAndFetch();
        $late = collect($slip->attendance_lines)->firstWhere('kind', 'late');

        $this->assertNotNull($late);
        $this->assertSame('2026-07-07', $late['date']);
        $this->assertStringContainsString('min late', (string) $late['detail']);
    }

    public function test_a_clean_period_records_no_lines(): void
    {
        foreach (['01', '02', '03', '06', '07', '08', '09', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }

        $slip = $this->runAndFetch();

        $this->assertSame(0.0, (float) $slip->unpaid_days);
        $this->assertNull($slip->attendance_lines, 'Nothing to explain means nothing shown.');
    }

    public function test_the_resource_groups_the_ledger_for_the_document(): void
    {
        $this->chargeLateness();

        foreach (['01', '02', '03', '06', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }
        $this->present('2026-07-07', '11:00');

        $payload = $this->payslipPayload($this->runAndFetch());

        $this->assertNotNull($payload['attendance_lines']);
        $this->assertNotEmpty($payload['attendance_lines']['unpaid']);
        $this->assertNotEmpty($payload['attendance_lines']['late']);

        // THE GROUP MUST ACCOUNT FOR THE WHOLE FIGURE, decimal included. Lateness therefore
        // appears in `unpaid` as well as in `late`: it costs a fraction of a day, and a note
        // listing only the absences under "4.5 unpaid days" leaves the .5 unexplained — which
        // is worse than no note, because the reader can see it does not add up.
        $this->assertEqualsWithDelta(
            (float) $payload['unpaid_days'],
            array_sum(array_column($payload['attendance_lines']['unpaid'], 'days')),
            0.0001,
        );
        $this->assertContains(
            'late',
            array_column($payload['attendance_lines']['unpaid'], 'kind'),
        );
    }

    public function test_the_deduction_rule_explains_how_minutes_became_days(): void
    {
        // The step nobody can derive from the payslip: a tier ladder makes 20 minutes late and
        // 200 minutes late cost exactly the same, and nothing on the document used to say so.
        app(AttendancePolicyService::class)->update([
            'late_deduction_mode' => 'tiered',
            'late_tiers' => [['over' => 15, 'hours' => 1]],
        ]);

        foreach (['01', '02', '03', '06', '08', '09', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }
        $this->present('2026-07-07', '11:00');

        $slip = $this->runAndFetch();
        $payload = $this->payslipPayload($slip);
        $rule = $payload['deduction_rule'];

        $this->assertNotNull($rule);
        $this->assertSame('tiered', $rule['late_mode']);
        $this->assertSame(8, $rule['hours_per_day']);

        // The stated rule must produce the charged days: one tier hour ÷ an 8-hour day.
        $charged = array_sum(array_column($payload['attendance_lines']['late'], 'days'));
        $this->assertEqualsWithDelta(
            $rule['late_tiers'][0]['hours'] / $rule['hours_per_day'],
            $charged,
            0.0001,
        );
        // …and the charged days must produce the peso figure printed on the deductions side.
        $this->assertEqualsWithDelta(
            $charged * (float) $slip->daily_rate,
            (float) $slip->late_deduction,
            0.05,
        );
    }

    public function test_no_deduction_rule_when_nothing_was_charged(): void
    {
        foreach (['01', '02', '03', '06', '07', '08', '09', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }

        // Default policy charges nothing for lateness — an explanation of a rule that took
        // nothing is noise.
        $this->assertNull($this->payslipPayload($this->runAndFetch())['deduction_rule']);
    }

    // --- the tax derivation --------------------------------------------------------------

    public function test_the_tax_basis_reconciles_with_the_withheld_figure(): void
    {
        foreach (['01', '02', '03', '06', '07', '08', '09', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }

        $slip = $this->runAndFetch();
        $basis = $slip->tax_basis;

        $this->assertNotNull($basis, 'A ₱40,000 salary is taxed — the control for everything below.');

        // Each step of the stated derivation must produce the next one.
        $this->assertEqualsWithDelta(
            ($basis['taxable_monthly'] - $basis['contributions_monthly']) * 12,
            $basis['annual_taxable'],
            0.05,
        );
        $this->assertEqualsWithDelta($basis['annual_tax'] / 12, $basis['monthly_tax'], 0.05);
        $this->assertEqualsWithDelta(
            round($basis['monthly_tax'] * $basis['timing_factor'], 2),
            (float) $slip->withholding_tax,
            0.05,
            'The derivation must land on the figure printed beside it.',
        );
    }

    public function test_the_tax_basis_cites_the_band_the_income_landed_in(): void
    {
        foreach (['01', '02', '03', '06', '07', '08', '09', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }

        $basis = $this->runAndFetch()->tax_basis;

        $this->assertNotNull($basis['band']);
        $this->assertGreaterThan($basis['band']['min'], $basis['annual_taxable']);
        // The cited band must be the one that produces the cited tax, not merely a plausible one.
        $expected = $basis['band']['base']
            + $basis['band']['rate'] * ($basis['annual_taxable'] - $basis['band']['min']);
        $this->assertEqualsWithDelta($expected, $basis['annual_tax'], 0.05);
    }

    public function test_an_untaxed_payslip_carries_no_derivation(): void
    {
        // Below the exempt threshold — an empty derivation under a ₱0 line is noise.
        $this->employee->update(['base_salary' => 12000]);

        foreach (['01', '02', '03', '06', '07', '08', '09', '10', '13', '14', '15'] as $d) {
            $this->present("2026-07-{$d}");
        }

        $slip = $this->runAndFetch();

        $this->assertSame(0.0, (float) $slip->withholding_tax);
        $this->assertNull($slip->tax_basis);
    }

    /**
     * The payslip as the API would send it to its owner.
     *
     * @return array<string, mixed>
     */
    private function payslipPayload(Payslip $slip): array
    {
        $slip->load(['payrollRun.runner', 'employee.user', 'employee.department']);

        $request = \Illuminate\Http\Request::create('/api/v1/hr/me/payslips/' . $slip->id);
        $request->setUserResolver(fn () => $this->employee->user);

        return \App\Modules\Hr\Http\Resources\PayslipResource::make($slip)->toArray($request);
    }
}
