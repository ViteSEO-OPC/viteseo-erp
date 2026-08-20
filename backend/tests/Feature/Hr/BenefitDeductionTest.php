<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\BenefitDeductionService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** (2026-07-28 audit) — payroll finally reads Company → Benefits. */
class BenefitDeductionTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

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
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function enrol(string $name, ?string $cadence, ?float $share, bool $active = true): BenefitProgram
    {
        $program = BenefitProgram::create([
            'name' => $name, 'category' => 'hmo', 'kind' => 'company',
            'frequency' => 'monthly', 'deduction_cadence' => $cadence,
            'tax_treatment' => 'de_minimis',
        ]);

        $this->employee->benefitPrograms()->attach($program->id, ['employee_share' => $share]);

        // B13 item 4 — a programme is switched off by deleting it; the enrolment row stays, so
        // this still exercises "enrolled in something that is no longer live".
        if (! $active) {
            $program->delete();
        }

        return $program;
    }

    /** @return array{total: float, lines: array<int, array{name: string, amount: float}>} */
    private function deductions(int $periodsInMonth = 2): array
    {
        return app(BenefitDeductionService::class)
            ->forPeriod($this->employee->fresh(), $periodsInMonth);
    }

    /** THE BUG: a configured share was never deducted. */
    public function test_a_per_cutoff_share_is_deducted_every_period(): void
    {
        $this->enrol('MediCare Plus HMO', 'per_cutoff', 500);

        $result = $this->deductions();

        $this->assertSame(500.0, $result['total']);
        $this->assertSame([['name' => 'MediCare Plus HMO', 'amount' => 500.0]], $result['lines']);
    }

    /** A MONTHLY share is split across the month's periods, like statutory contributions. */
    public function test_a_monthly_share_is_split_across_the_periods(): void
    {
        $this->enrol('Union Dues', 'monthly', 600);

        $this->assertSame(300.0, $this->deductions(periodsInMonth: 2)['total']);
        $this->assertSame(600.0, $this->deductions(periodsInMonth: 1)['total']);
    }

    /** No cadence = not a payroll deduction at all. */
    public function test_a_programme_without_a_cadence_is_not_deducted(): void
    {
        $this->enrol('Retirement Plus', null, 500);

        $this->assertSame(0.0, $this->deductions()['total']);
    }

    /** The common case: the company pays the whole thing. */
    public function test_a_zero_share_deducts_nothing(): void
    {
        $this->enrol('Company-paid HMO', 'per_cutoff', null);

        $this->assertSame(0.0, $this->deductions()['total']);
    }

    public function test_an_inactive_programme_is_not_deducted(): void
    {
        $this->enrol('Old Plan', 'per_cutoff', 500, active: false);

        $this->assertSame(0.0, $this->deductions()['total']);
    }

    public function test_multiple_programmes_are_itemised(): void
    {
        $this->enrol('HMO', 'per_cutoff', 500);
        $this->enrol('Union Dues', 'per_cutoff', 100);

        $result = $this->deductions();

        $this->assertSame(600.0, $result['total']);
        $this->assertCount(2, $result['lines']);
    }

    // --- payslip integration --------------------------------------------------------

    /** @return array<string, mixed> */
    private function slip(): array
    {
        return app(PayrollService::class)->payslipFor(
            employee: $this->employee->fresh(),
            reimb: 0.0,
            unpaidDays: 0.0,
            periodsPerYear: 24,
            workingDaysInPeriod: 11,
            dist: ['index' => 0, 'count' => 2, 'deduction_timing' => 'every', 'tax_timing' => 'every'],
        );
    }

    public function test_the_share_reaches_the_payslip_and_reduces_net_pay(): void
    {
        $before = $this->slip();

        $this->enrol('MediCare Plus HMO', 'per_cutoff', 500);
        $after = $this->slip();

        $this->assertSame(0.0, $before['other_deductions']);
        $this->assertSame(500.0, $after['other_deductions']);
        $this->assertSame($before['total_deductions'] + 500.0, $after['total_deductions']);
        $this->assertSame($before['net_pay'] - 500.0, $after['net_pay']);
        // Gross is untouched — this is a deduction, not negative pay.
        $this->assertSame($before['gross'], $after['gross']);
    }

    /** Post-tax: a benefit share is not a statutory contribution, so tax is unchanged. */
    public function test_the_share_does_not_change_withholding_tax(): void
    {
        $before = $this->slip();

        $this->enrol('MediCare Plus HMO', 'per_cutoff', 500);
        $after = $this->slip();

        $this->assertSame($before['withholding_tax'], $after['withholding_tax']);
    }

    /** The breakdown is stored so the payslip can name each line. */
    public function test_the_breakdown_is_persisted_on_the_payslip(): void
    {
        $this->enrol('HMO', 'per_cutoff', 500);
        $this->enrol('Union Dues', 'per_cutoff', 100);

        $breakdown = $this->slip()['other_deductions_breakdown'];

        $this->assertCount(2, $breakdown);
        $this->assertSame('HMO', $breakdown[0]['name']);
    }
}
