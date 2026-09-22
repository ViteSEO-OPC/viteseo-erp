<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\InternAllowanceService;
use App\Modules\Hr\Services\LoanService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Support\InternAllowancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Interns inside the regular payroll run: paid an allowance under Company → Intern Allowance, in
 * the same run as everyone else, with nothing taken off it but what attendance takes.
 *
 * The cycle is 1–15 Sep 2026: eleven workdays (Tue 1 – Tue 15), the intern absent on Thu 3.
 */
class InternPayrollRunTest extends HrTestCase
{
    use RefreshDatabase;

    private const START = '2026-09-01';

    private const END = '2026-09-15';

    private Employee $intern;

    private Employee $regular;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-20 09:00', config('app.timezone_local')));
        $this->seed(ContributionSettingSeeder::class);

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00',
            'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        // A company that pays interns ₱500 a day, and always has.
        app(InternAllowanceService::class)->update(array_merge(InternAllowancePolicy::DEFAULTS, [
            'daily_amount' => 500,
            'effective_from' => InternAllowanceService::OPENING_DATE,
        ]));

        $dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $this->intern = $this->employee($dept, 'E-OJT', 'ojt', 0);
        $this->regular = $this->employee($dept, 'E-REG', 'regular', 30000);

        foreach ($this->workdays() as $date) {
            $this->punch($this->regular, $date);
            if ($date !== '2026-09-03') {
                $this->punch($this->intern, $date);
            }
        }
    }

    private function employee(Department $dept, string $no, string $status, float $salary): Employee
    {
        $employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => $no, 'job_title' => 'Engineer', 'employment_status' => $status,
            'hire_date' => '2026-06-01', 'base_salary' => $salary, 'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id, 'base_salary' => $salary,
            'effective_date' => '2026-06-01', 'reason' => 'Hire', 'change_type' => 'hire',
        ]);

        return $employee;
    }

    /** @return list<string> */
    private function workdays(): array
    {
        return ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08',
            '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15'];
    }

    private function punch(Employee $employee, string $date): void
    {
        $tz = config('app.timezone_local');

        Attendance::create([
            'employee_id' => $employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} 08:00", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} 17:00", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function runPayroll(): void
    {
        app(PayrollService::class)->run(self::START, self::END, 'semi_monthly', User::factory()->create());
    }

    private function slipFor(Employee $employee): Payslip
    {
        return Payslip::where('employee_id', $employee->id)->firstOrFail();
    }

    // --- the allowance ------------------------------------------------------------------------

    public function test_an_intern_is_paid_the_allowance_for_the_days_the_rules_pay(): void
    {
        $this->runPayroll();
        $slip = $this->slipFor($this->intern);

        $this->assertTrue($slip->is_intern);
        $this->assertEquals(5500, (float) $slip->period_base);        // 11 days × ₱500
        $this->assertEquals(500, (float) $slip->absence_deduction);   // Thu 3 Sep
        $this->assertEquals(5000, (float) $slip->base_salary);
        $this->assertEquals(5000, (float) $slip->net_pay);
        $this->assertSame('per_day', $slip->intern_basis['method']);
        $this->assertSame(InternAllowanceService::OPENING_DATE, $slip->intern_basis['effective_from']);
    }

    /** Enrolled or not, an allowance carries no contribution and no tax. */
    public function test_nothing_statutory_is_taken_off_an_intern_and_a_colleague_is_unaffected(): void
    {
        $this->runPayroll();
        $intern = $this->slipFor($this->intern);
        $regular = $this->slipFor($this->regular);

        foreach (['sss', 'philhealth', 'pagibig', 'withholding_tax', 'other_deductions', 'total_deductions', 'taxable_pay'] as $column) {
            $this->assertEquals(0, (float) $intern->{$column}, "an intern's {$column} must be nothing");
        }

        $this->assertFalse($regular->is_intern);
        $this->assertGreaterThan(0, (float) $regular->sss, 'the salaried colleague in the same run still contributes');
    }

    public function test_the_run_records_the_rules_interns_were_priced_under(): void
    {
        $this->runPayroll();

        $snapshot = Payslip::where('employee_id', $this->intern->id)->firstOrFail()->payrollRun->policy_snapshot;

        $this->assertEquals(500, $snapshot['intern_allowance']['daily_amount']);
        $this->assertSame([], app(PayrollService::class)->snapshotDrift(Payslip::firstOrFail()->payrollRun));
    }

    public function test_a_rerun_prices_an_intern_the_same(): void
    {
        $this->runPayroll();
        $first = (float) $this->slipFor($this->intern)->net_pay;

        $this->runPayroll();

        $this->assertSame($first, (float) $this->slipFor($this->intern)->net_pay);
        $this->assertSame(1, Payslip::where('employee_id', $this->intern->id)->count());
    }

    public function test_an_interns_own_allowance_overrides_the_company_default(): void
    {
        $this->intern->update(['allowance_amount' => 650]);

        $this->runPayroll();
        $slip = $this->slipFor($this->intern);

        $this->assertEquals(6500, (float) $slip->net_pay); // 10 days × ₱650
        $this->assertSame('override', $slip->intern_basis['amount_source']);
    }

    public function test_an_intern_cannot_be_given_a_loan(): void
    {
        $this->expectException(ValidationException::class);

        app(LoanService::class)->create($this->intern, [
            'principal' => 5000, 'per_period_amount' => 500, 'start_date' => self::START,
        ], User::factory()->create());
    }

    // --- the payslip document ---------------------------------------------------------------------

    private function pdfHtml(Payslip $slip): string
    {
        return view('hr::payslip', app(PayrollService::class)->payslipViewData($slip))->render();
    }

    public function test_an_intern_payslip_prints_as_an_allowance_with_no_government_table(): void
    {
        $this->runPayroll();

        $intern = $this->pdfHtml($this->slipFor($this->intern));
        $regular = $this->pdfHtml($this->slipFor($this->regular));

        $this->assertStringContainsString('Total Allowance', $intern);
        $this->assertStringNotContainsString('Government Benefits', $intern);
        $this->assertStringContainsString('Government Benefits', $regular);
    }

    public function test_the_payslip_api_reads_as_an_intern_allowance(): void
    {
        $this->runPayroll();
        $slip = $this->slipFor($this->intern);

        $response = $this->actingAsHr()->getJson("/api/v1/hr/payslips/{$slip->id}")->assertOk();

        $response->assertJsonPath('data.is_intern', true)
            ->assertJsonPath('data.earnings_breakdown.0.name', 'Allowance')
            ->assertJsonPath('data.earnings_breakdown.0.note', '11 days × ₱500.00')
            ->assertJsonPath('data.deduction_rule', null);
        $this->assertEquals(500, $response->json('data.rates.daily'));
        $this->assertEquals(62.5, $response->json('data.rates.hourly'));
    }

    /** A converted intern's old payslip is still the allowance it was. */
    public function test_an_intern_payslip_keeps_its_layout_after_the_intern_is_converted(): void
    {
        $this->runPayroll();

        app(EmployeeService::class)->convertIntern($this->intern, [
            'employment_status' => 'regular', 'effective_date' => '2026-09-16', 'base_salary' => 30000,
        ]);

        $this->assertStringNotContainsString('Government Benefits', $this->pdfHtml($this->slipFor($this->intern->fresh())));
    }

    // --- conversion ----------------------------------------------------------------------------

    /** Dated after the cycle, the conversion leaves the whole cycle an internship. */
    public function test_a_conversion_dated_after_the_cycle_leaves_it_an_internship(): void
    {
        app(EmployeeService::class)->convertIntern($this->intern, [
            'employment_status' => 'regular', 'effective_date' => '2026-09-16', 'base_salary' => 30000,
        ]);

        $this->runPayroll();

        $this->assertTrue($this->slipFor($this->intern->fresh())->is_intern);
    }

    /**
     * Converted on Tue 8 Sep: the salary pays from the 8th, and Tue 1 – Mon 7 (five workdays, one of
     * them the absence) is paid on the same slip as the intern allowance — ₱2,000, untaxed.
     */
    public function test_a_conversion_inside_the_cycle_splits_the_slip_at_the_conversion(): void
    {
        app(EmployeeService::class)->convertIntern($this->intern, [
            'employment_status' => 'regular', 'effective_date' => '2026-09-08', 'base_salary' => 30000,
        ]);

        $this->runPayroll();
        $slip = $this->slipFor($this->intern->fresh());

        $this->assertFalse($slip->is_intern);

        $internLine = collect($slip->allowances_breakdown)->firstWhere('name', 'Intern Allowance (Sep 1–Sep 7)');
        $this->assertNotNull($internLine, 'the days before the conversion are paid as an intern allowance line');
        $this->assertEquals(2000, (float) $internLine['amount']); // 5 days × ₱500, less the absence

        $preConversion = collect($slip->attendance_lines)->where('kind', 'pre_conversion');
        $this->assertCount(5, $preConversion, 'the salary does not also pay the five workdays before the conversion');
        $this->assertSame('2026-09-07', $slip->intern_basis['until']);
    }

    // --- rest days ---------------------------------------------------------------------------------

    /**
     * The first eight hours of a worked rest day ARE the rest-day premium, so only the excess can
     * be overtime — the cap every salaried employee's run applies. An intern's pricing has to be
     * handed the same rest-day map to apply it, or the same minutes are paid twice: once at the
     * rest-day rate and again as overtime.
     *
     * Sat 5 Sep, 08:00–17:00 (eight hours after lunch), with 16:00–17:00 approved as overtime.
     */
    public function test_a_worked_rest_day_is_not_paid_as_premium_and_overtime_over_the_same_minutes(): void
    {
        app(InternAllowanceService::class)->update(array_merge(InternAllowancePolicy::DEFAULTS, [
            'daily_amount' => 500,
            'rest_day_worked' => 'allowance_with_premium',
            'overtime_rule' => 'with_premium',
            'effective_from' => InternAllowanceService::OPENING_DATE,
        ]));

        $this->punch($this->intern, '2026-09-05');
        OvertimeRequest::create([
            'employee_id' => $this->intern->id, 'work_date' => '2026-09-05',
            'time_from' => '16:00', 'time_to' => '17:00', 'hours' => 1,
            'reason' => 'Release', 'status' => 'approved', 'reviewed_at' => now(),
        ]);

        $this->runPayroll();
        $slip = $this->slipFor($this->intern);

        $this->assertEquals(650, (float) $slip->rest_day_premium); // ₱500 × 130%, the whole day worked
        $this->assertEquals(0, (float) $slip->overtime_pay, 'the first eight hours are already the premium');
        $this->assertSame(0, (int) $slip->overtime_minutes);
    }
}
