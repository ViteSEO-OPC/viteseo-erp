<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * FIN-014 — final pay: the most legally scrutinised payslip a company issues, and it was mostly absent.
 *
 * The regular run already prorated a leaver's last cycle (Slice 4, `withTrashed()`). Everything else
 * was missing: no separation pay, no conversion of unused leave to cash, no settlement of outstanding
 * loans, no tax annualisation. Loans in particular simply STOPPED being collected — `collectForPeriod()`
 * only runs for a pay group the employee is no longer in, so the balance sat on the books with nothing
 * left to recover it from.
 *
 * SEPARATION PAY IS ENTERED, NOT COMPUTED, and that is a deliberate scope line rather than an omission.
 * Articles 298–299 set different formulas per cause, the entitlement turns on facts this system does not
 * hold, and a just-cause dismissal carries none. Encoding unsigned-off multipliers would be the
 * FIN-024 / FIN-030 mistake with a payment as the output instead of a warning.
 *
 * THE TAX ASSERTIONS ARE THE ONES TO READ. Monetised unused VL is exempt up to 12 days (raised from 10
 * by RR 29-2025), the excess joins the ₱90,000 bucket rather than becoming taxable compensation, and
 * the year is trued up against what was actually earned — which for a mid-year leaver is nearly always
 * a refund, because they were withheld all year against a full-year income they never reached.
 */
class FinalPayTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-08-01 12:00', config('app.timezone_local')));

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
            'separation_type' => 'resignation', 'separation_date' => '2026-06-30',
        ]);
    }

    /** Half a year of ordinary payslips, so the annualisation has a real year to true up against. */
    private function payJanToJune(float $monthlyTaxable = 30000): void
    {
        foreach (range(1, 6) as $month) {
            $start = sprintf('2026-%02d-01', $month);
            $run = PayrollRun::firstOrCreate(
                ['cycle_start' => $start, 'cycle_end' => date('Y-m-t', strtotime($start)), 'frequency' => 'monthly'],
                ['type' => 'regular', 'status' => 'completed', 'run_at' => now()],
            );
            Payslip::create([
                'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
                'base_salary' => $monthlyTaxable, 'gross' => $monthlyTaxable,
                'taxable_pay' => $monthlyTaxable,
                // What annualising each month alone would have withheld — the projection.
                'withholding_tax' => round(
                    app(\App\Modules\Hr\Services\TaxService::class)->annualTax($monthlyTaxable * 12, $start) / 12,
                    2,
                ),
                'total_deductions' => 0, 'net_pay' => $monthlyTaxable, 'status' => 'disbursed',
            ]);
        }
    }

    private function giveLeaveBalance(int $entitled, int $used = 0): void
    {
        $type = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'is_paid' => true]);

        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2026, 'entitled_days' => $entitled, 'used_days' => $used,
        ]);
    }

    /**
     * Named `finalPay`, not `run` — PHPUnit's TestCase::run() is final.
     *
     * @param  array<string, mixed>  $data
     */
    private function finalPay(array $data = []): PayrollRun
    {
        return app(PayrollService::class)->runFinalPay(
            $this->employee->fresh(),
            array_merge(['reason' => 'Resignation effective 30 June 2026'], $data),
            $this->hrUser(),
        );
    }

    private function slip(PayrollRun $run): Payslip
    {
        return Payslip::where('payroll_run_id', $run->id)->firstOrFail();
    }

    // --- the four things it pays --------------------------------------------------------------

    public function test_it_converts_unused_leave_at_the_daily_rate(): void
    {
        $this->giveLeaveBalance(entitled: 10, used: 4);

        $slip = $this->slip($this->finalPay());

        $this->assertSame(6.0, (float) $slip->leave_conversion_days, '10 entitled − 4 used');
        $this->assertGreaterThan(0, (float) $slip->leave_conversion);
        $this->assertSame(
            round(6 * (float) $slip->leave_conversion / 6, 2),
            round((float) $slip->leave_conversion, 2),
        );
    }

    public function test_hr_can_override_the_leave_days(): void
    {
        $this->giveLeaveBalance(entitled: 10);

        // Final settlements are frequently negotiated; a system that refused to record what was
        // agreed would simply be worked around in a spreadsheet.
        $slip = $this->slip($this->finalPay(['unused_leave_days' => 3]));

        $this->assertSame(3.0, (float) $slip->leave_conversion_days);
    }

    public function test_it_pays_the_separation_amount_hr_entered(): void
    {
        $slip = $this->slip($this->finalPay(['separation_pay' => 45000]));

        $this->assertSame(45000.0, (float) $slip->separation_pay);
        $this->assertSame(45000.0, (float) $slip->gross, 'no leave balance, so gross is the separation pay');
    }

    /**
     * THE GAP THAT MATTERED MOST. A leaver's loan stopped being collected entirely, because
     * `collectForPeriod()` only runs for a pay group they are no longer in.
     */
    public function test_it_settles_the_whole_outstanding_loan_balance(): void
    {
        EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'active',
        ]);

        $run = $this->finalPay(['separation_pay' => 50000]);
        $slip = $this->slip($run);

        $this->assertSame(12000.0, (float) $slip->loan_settlement, 'the FULL balance, not one instalment');
        $this->assertSame('settled', EmployeeLoan::first()->status);
        $this->assertSame(0.0, EmployeeLoan::first()->outstanding());
    }

    /** What the final pay cannot cover stays outstanding rather than being written off. */
    public function test_a_loan_larger_than_the_final_pay_is_only_partly_settled(): void
    {
        EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 90000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'active',
        ]);

        $slip = $this->slip($this->finalPay(['separation_pay' => 5000]));

        $this->assertLessThan(90000.0, (float) $slip->loan_settlement);
        $this->assertGreaterThan(0, EmployeeLoan::first()->outstanding(), 'the rest must stay on the books');
        $this->assertSame('active', EmployeeLoan::first()->status);
    }

    // --- tax ------------------------------------------------------------------------------------

    /** Up to 12 days is exempt (RR 29-2025 raised it from 10), so it adds nothing taxable. */
    public function test_leave_conversion_within_twelve_days_is_exempt(): void
    {
        $this->giveLeaveBalance(entitled: 12);

        $slip = $this->slip($this->finalPay());

        $this->assertSame(12.0, (float) $slip->leave_conversion_days);
        $this->assertSame(0.0, (float) $slip->other_benefits, '12 days is entirely within the exemption');
    }

    /** Beyond 12 days the excess joins the ₱90,000 bucket — not taxable compensation directly. */
    public function test_leave_conversion_beyond_twelve_days_enters_the_other_benefits_bucket(): void
    {
        $this->giveLeaveBalance(entitled: 20);

        $slip = $this->slip($this->finalPay());

        $this->assertSame(20.0, (float) $slip->leave_conversion_days);
        $this->assertGreaterThan(0, (float) $slip->other_benefits, '8 days over the exemption');
    }

    /** Separation pay for a cause beyond the employee's control is exempt (NIRC §32(B)(6)(b)). */
    public function test_separation_pay_is_exempt_by_default(): void
    {
        $slip = $this->slip($this->finalPay(['separation_pay' => 200000]));

        $this->assertFalse((bool) $slip->separation_pay_taxable);
        $this->assertSame(0.0, (float) $slip->taxable_pay, 'an exempt package adds nothing taxable');
    }

    public function test_hr_can_mark_separation_pay_taxable(): void
    {
        $slip = $this->slip($this->finalPay([
            'separation_pay' => 200000,
            'separation_pay_taxable' => true,
        ]));

        $this->assertTrue((bool) $slip->separation_pay_taxable);
        $this->assertSame(200000.0, (float) $slip->taxable_pay);
    }

    /**
     * A mid-year leaver was withheld all year against a full-year income they never reached, so the
     * true-up is a refund. Clamping it at zero would keep tax they never owed.
     */
    public function test_a_mid_year_leaver_is_refunded_over_withheld_tax(): void
    {
        $this->payJanToJune();

        $slip = $this->slip($this->finalPay());

        $this->assertLessThan(0, (float) $slip->withholding_tax, 'the year should settle as a refund');
        $this->assertGreaterThan(
            (float) $slip->gross,
            (float) $slip->net_pay,
            'a refund must lift net above gross — nothing may clamp it',
        );
    }

    // --- the guards -----------------------------------------------------------------------------

    public function test_an_employee_with_no_separation_date_is_refused(): void
    {
        $this->employee->update(['separation_date' => null]);

        $this->expectException(ValidationException::class);
        $this->finalPay();
    }

    public function test_recomputing_replaces_the_previous_figures(): void
    {
        $first = $this->finalPay(['separation_pay' => 10000]);
        $second = $this->finalPay(['separation_pay' => 25000]);

        $this->assertSame($first->id, $second->id, 'the same run should be recomputed in place');
        $this->assertSame(25000.0, (float) $this->slip($second)->separation_pay);
        $this->assertSame(1, Payslip::where('payroll_run_id', $second->id)->count());
    }

    public function test_a_disbursed_final_pay_cannot_be_recomputed(): void
    {
        $run = $this->finalPay(['separation_pay' => 10000]);
        app(PayrollService::class)->markDone($this->slip($run), $this->hrUser());

        $this->expectException(ValidationException::class);
        $this->finalPay(['separation_pay' => 99999]);
    }

    /** It must not disturb the regular run that pays the leaver's last worked days. */
    public function test_it_does_not_block_the_regular_run_for_the_same_period(): void
    {
        $this->finalPay(['separation_pay' => 10000]);

        $regular = app(PayrollService::class)->run(
            '2026-06-16', '2026-06-30', 'semi_monthly', User::factory()->create(),
        );

        $this->assertSame(1, $regular->employee_count, 'the leaver should still be paid for days worked');
    }
}
