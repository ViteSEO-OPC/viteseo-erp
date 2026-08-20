<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\LoanPayment;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\LoanService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** / — loan interest, fees and collection priority. */
class LoanInterestTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

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

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->workAugust();
    }

    private function workAugust(): void
    {
        $tz = config('app.timezone_local');

        for ($day = Carbon::parse('2026-08-01', $tz); $day->month === 8; $day->addDay()) {
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

    /** @param array<string, mixed> $attrs */
    private function loan(array $attrs = []): EmployeeLoan
    {
        return EmployeeLoan::create(array_merge([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'active',
            'authorized_at' => now(),
        ], $attrs));
    }

    /** The first August cycle, run for real. */
    private function payFirstHalf(): \App\Modules\Hr\Models\PayrollRun
    {
        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    // --- the rate ---------------------------------------------------------------------------------

    /** THE GUARD ON THE MIGRATION. Zero rate = today's behaviour, unchanged. */
    public function test_a_zero_rate_charges_nothing(): void
    {
        $loan = $this->loan();
        $run = $this->payFirstHalf();

        $payment = LoanPayment::where('loan_id', $loan->id)->firstOrFail();

        $this->assertSame(0.0, (float) $payment->interest_amount);
        $this->assertSame(1000.0, (float) $payment->principal_amount);
        $this->assertSame(1000.0, (float) $payment->amount);
        $this->assertSame(0.0, (float) $run->payslips()->first()->loan_interest);
    }

    /** An annual rate is split across the year's paydays — 24 for semi-monthly. */
    public function test_an_annual_rate_is_split_per_payday(): void
    {
        $loan = $this->loan(['interest_rate' => 12, 'interest_period' => 'annual']);

        // 12% a year over 24 semi-monthly periods = 0.5% per period, on ₱12,000 = ₱60.
        $this->assertSame(60.0, $loan->periodInterest(24));
    }

    /**
     * A MONTHLY rate is converted through the year, so a semi-monthly employee pays half a month's
     * interest per payday rather than a whole month's. Reading "2%" as annual when the company meant
     * monthly is a twelve-fold error, which is why the period is stored rather than assumed.
     */
    public function test_a_monthly_rate_is_not_charged_twice_a_month(): void
    {
        $monthly = $this->loan(['interest_rate' => 1, 'interest_period' => 'monthly']);

        // 1%/month = 12%/year → 0.5% per semi-monthly period on ₱12,000 = ₱60, not ₱120.
        $this->assertSame(60.0, $monthly->periodInterest(24));
        // And a monthly-paid employee gets the whole month's worth.
        $this->assertSame(120.0, $monthly->periodInterest(12));
    }

    // --- diminishing balance ----------------------------------------------------------------------

    /**
     * THE ONE THAT MATTERS. `outstanding()` reads `principal_amount`, so interest cannot reduce the
     * balance it was charged on — otherwise a loan repays itself out of its own interest.
     */
    public function test_interest_never_reduces_the_balance_it_is_charged_on(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);
        $this->payFirstHalf();

        $fresh = $loan->fresh();

        $this->assertSame(11000.0, $fresh->outstanding(), '12,000 − 1,000 principal, not − 1,060');
        $this->assertSame(1000.0, $fresh->paidPrincipal());
        $this->assertSame(60.0, $fresh->paidInterest());
        $this->assertSame(1060.0, $fresh->paid(), 'the total collected does include it');
    }

    /** Diminishing: the interest charge falls as the balance does. */
    public function test_the_interest_charge_falls_as_the_loan_is_repaid(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);
        $payroll = app(PayrollService::class);

        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());

        $charges = LoanPayment::where('loan_id', $loan->id)
            ->orderBy('id')
            ->pluck('interest_amount')
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->assertSame(60.0, $charges[0], '0.5% of 12,000');
        $this->assertSame(55.0, $charges[1], '0.5% of 11,000 — lower, because the balance is lower');
    }

    // --- the payslip ------------------------------------------------------------------------------

    /** Its own line, so the employee sees what reduced the balance and what the money cost. */
    public function test_the_payslip_shows_interest_as_its_own_line(): void
    {
        $this->loan(['interest_rate' => 12]);
        $run = $this->payFirstHalf();

        $slip = $run->payslips()->firstOrFail();
        $names = array_column($slip->other_deductions_breakdown, 'name');

        $this->assertContains('Loan', $names);
        $this->assertContains('Loan — interest', $names);
        $this->assertSame(60.0, (float) $slip->loan_interest);
    }

    /** Principal + interest both come out of net pay. */
    public function test_net_pay_absorbs_both_principal_and_interest(): void
    {
        $withoutInterest = $this->payFirstHalf()->payslips()->first()->net_pay;
        $this->payFirstHalf()->payslips()->first()->payrollRun?->payslips()->forceDelete();

        // Re-run with a rate on the loan and compare.
        $this->loan(['interest_rate' => 12]);
        $withInterest = $this->payFirstHalf()->payslips()->firstOrFail()->net_pay;

        $this->assertSame(
            round((float) $withoutInterest - 1060, 2),
            round((float) $withInterest, 2),
            'the 1,000 instalment and the 60 interest both reduce net pay',
        );
    }

    // --- fees --------------------------------------------------------------------------------------

    /** A one-off fee, collected with the first instalment and never again. */
    public function test_a_fee_is_collected_once(): void
    {
        $loan = $this->loan(['fees' => 150]);
        $payroll = app(PayrollService::class);

        $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payroll->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());

        $fees = LoanPayment::where('loan_id', $loan->id)
            ->orderBy('id')
            ->pluck('fee_amount')
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->assertSame(150.0, $fees[0]);
        $this->assertSame(0.0, $fees[1]);
        $this->assertSame(150.0, $loan->fresh()->paidFees());
    }

    /** An uncollected fee keeps the loan open — writing it off silently would be the alternative. */
    public function test_an_outstanding_fee_keeps_the_loan_collectible(): void
    {
        $loan = $this->loan(['principal' => 1000, 'per_period_amount' => 1000, 'fees' => 500]);

        // Repay the whole principal but none of the fee. A BARE run, not a real payroll run: running
        // payroll would itself write a payment for this (loan, run) pair, and the table has a unique
        // index on it.
        LoanPayment::create([
            'loan_id' => $loan->id,
            'payroll_run_id' => \App\Modules\Hr\Models\PayrollRun::create([
                'cycle_start' => '2026-07-01', 'cycle_end' => '2026-07-15',
                'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
                'run_at' => now(),
            ])->id,
            'payslip_id' => null,
            'amount' => 1000, 'principal_amount' => 1000, 'interest_amount' => 0, 'fee_amount' => 0,
        ]);

        $fresh = $loan->fresh();
        $this->assertSame(0.0, $fresh->outstanding());
        $this->assertSame(500.0, $fresh->outstandingFees());
        $this->assertTrue($fresh->isCollectible());
    }

    // --- allocation when the payslip is short ------------------------------------------------------

    /**
     * INTEREST FIRST, THEN FEE, THEN PRINCIPAL. Interest is the cost of the money for this period;
     * reducing principal while leaving it unpaid would charge the employee for a balance they had
     * already paid down. The consequence — a short payday means less principal comes off, so the loan
     * runs longer — is how every real amortising loan behaves.
     */
    public function test_a_short_payslip_pays_interest_before_principal(): void
    {
        $loan = $this->loan(['interest_rate' => 12, 'fees' => 100]);

        // Only ₱200 available: 60 interest + 100 fee + 40 principal.
        $result = app(LoanService::class)->collectForPeriod(
            $this->employee,
            $this->payFirstHalf(),
            '2026-08-15',
            200,
            null,
            24,
        );

        $payment = LoanPayment::where('loan_id', $loan->id)->latest('id')->firstOrFail();

        $this->assertSame(60.0, (float) $payment->interest_amount);
        $this->assertSame(100.0, (float) $payment->fee_amount);
        $this->assertSame(40.0, (float) $payment->principal_amount);
        $this->assertSame(200.0, $result['total']);
        $this->assertSame(60.0, $result['interest']);
    }

    /** Nothing available means nothing collected — and nothing written off. */
    public function test_an_empty_payslip_collects_nothing_and_writes_nothing_off(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);

        app(LoanService::class)->collectForPeriod(
            $this->employee, $this->payFirstHalf(), '2026-08-15', 0, null, 24,
        );

        $this->assertSame(12000.0, $loan->fresh()->outstanding(), 'the full balance still stands');
    }

    // --- priority ----------------------------------------------------------------------------------

    /** Lower priority runs first, so HR decides who gets the money when there isn't enough. */
    public function test_priority_decides_which_loan_is_collected_first(): void
    {
        // The later-dated loan has the higher priority (lower number), so it must win.
        $second = $this->loan(['reference' => 'URGENT', 'start_date' => '2026-06-01', 'priority' => 1]);
        $first = $this->loan(['reference' => 'ROUTINE', 'start_date' => '2026-01-01', 'priority' => 50]);

        // Only enough for one instalment.
        app(LoanService::class)->collectForPeriod(
            $this->employee, $this->payFirstHalf(), '2026-08-15', 1000, null, 24,
        );

        $this->assertSame(1000.0, $second->fresh()->paidPrincipal(), 'priority 1 collected');
        $this->assertSame(0.0, $first->fresh()->paidPrincipal(), 'priority 50 got nothing');
    }

    /** Equal priority falls back to oldest-first, which is the fair tie-break. */
    public function test_equal_priority_falls_back_to_oldest_first(): void
    {
        $older = $this->loan(['reference' => 'A', 'start_date' => '2026-01-01']);
        $newer = $this->loan(['reference' => 'B', 'start_date' => '2026-06-01']);

        app(LoanService::class)->collectForPeriod(
            $this->employee, $this->payFirstHalf(), '2026-08-15', 1000, null, 24,
        );

        $this->assertSame(1000.0, $older->fresh()->paidPrincipal());
        $this->assertSame(0.0, $newer->fresh()->paidPrincipal());
    }

    // --- the amortisation schedule ----------------------------------------------------------------

    public function test_the_schedule_amortises_to_zero(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);

        $schedule = app(LoanService::class)->amortisationSchedule($loan, 24);

        $this->assertSame(12, $schedule['periods'], '12,000 at 1,000 a period');
        $this->assertFalse($schedule['truncated']);
        $this->assertSame(0.0, $schedule['rows'][11]['closing_balance']);
    }

    /**
     * The diminishing shape, made visible: interest falls every period while principal stays flat.
     * This is the argument for diminishing over flat, and the employee cannot check a rate they
     * cannot see.
     */
    public function test_the_schedule_shows_interest_falling(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);

        $rows = app(LoanService::class)->amortisationSchedule($loan, 24)['rows'];

        $this->assertSame(60.0, $rows[0]['interest'], '0.5% of 12,000');
        $this->assertSame(5.0, $rows[11]['interest'], '0.5% of 1,000');
        $this->assertSame($rows[0]['principal'], $rows[11]['principal'], 'principal stays flat');
    }

    /** Total interest on the diminishing schedule — well under what flat would have charged. */
    public function test_diminishing_costs_far_less_than_flat_would(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);

        $total = app(LoanService::class)->amortisationSchedule($loan, 24)['total_interest'];

        // Flat would be 12,000 x 12% = 1,440 regardless of repayment.
        $this->assertSame(390.0, $total);
        $this->assertLessThan(1440.0, $total);
    }

    /** The fee appears once, on the first period. */
    public function test_the_schedule_charges_the_fee_on_the_first_period_only(): void
    {
        $loan = $this->loan(['fees' => 150]);

        $rows = app(LoanService::class)->amortisationSchedule($loan, 24)['rows'];

        $this->assertSame(150.0, $rows[0]['fee']);
        $this->assertSame(0.0, $rows[1]['fee']);
    }

    /** A schedule too long to project is CUT and says so, rather than being presented as complete. */
    public function test_a_schedule_too_long_to_project_is_reported_as_truncated(): void
    {
        $loan = $this->loan(['interest_rate' => 12, 'per_period_amount' => 10]);

        $schedule = app(LoanService::class)->amortisationSchedule($loan, 24);

        $this->assertTrue($schedule['truncated']);
        $this->assertSame(600, $schedule['periods'], 'cut at the cap');
    }

    /** A loan with no instalment has no schedule to project — and that is a data problem, not a crash. */
    public function test_a_loan_with_no_instalment_yields_an_empty_schedule(): void
    {
        $loan = $this->loan(['per_period_amount' => 0]);

        $schedule = app(LoanService::class)->amortisationSchedule($loan, 24);

        $this->assertSame([], $schedule['rows']);
        $this->assertSame(0, $schedule['periods']);
    }

    // --- the HTTP surface -------------------------------------------------------------------------

    public function test_the_employee_can_see_their_own_schedule(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);

        $this->actingAs($this->employee->user)
            ->getJson("/api/v1/hr/loans/{$loan->id}/schedule")
            ->assertOk()
            ->assertJsonPath('meta.periods_per_year', 24)
            // Compared as a float: JSON renders 12.0000 as `12`, so pinning the encoded shape would be
            // testing PHP's serializer rather than the rate.
            ->assertJsonPath('meta.interest_rate', fn ($v) => (float) $v === 12.0)
            ->assertJsonPath('data.rows.0.interest', fn ($v) => (float) $v === 60.0);
    }

    /** Somebody else's loan is not theirs to read. */
    public function test_another_employee_cannot_see_the_schedule(): void
    {
        $loan = $this->loan(['interest_rate' => 12]);

        $other = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
        ]);

        $this->actingAs($other->user)
            ->getJson("/api/v1/hr/loans/{$loan->id}/schedule")
            ->assertForbidden();
    }

    public function test_hr_can_set_the_terms_over_http(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/loans", [
                'type' => 'loan',
                'principal' => 12000,
                'per_period_amount' => 1000,
                'start_date' => '2026-09-01',
                'interest_rate' => 10.5,
                'interest_period' => 'annual',
                'fees' => 150,
                'priority' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.interest_rate', fn ($v) => (float) $v === 10.5)
            ->assertJsonPath('data.interest_period', 'annual')
            ->assertJsonPath('data.fees', fn ($v) => (float) $v === 150.0)
            ->assertJsonPath('data.priority', 5);
    }

    public function test_a_rate_above_one_hundred_percent_is_refused(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/loans", [
                'type' => 'loan', 'principal' => 12000, 'per_period_amount' => 1000,
                'start_date' => '2026-09-01', 'interest_rate' => 250,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('interest_rate');
    }

    public function test_an_unknown_interest_period_is_refused(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/loans", [
                'type' => 'loan', 'principal' => 12000, 'per_period_amount' => 1000,
                'start_date' => '2026-09-01', 'interest_rate' => 6, 'interest_period' => 'weekly',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('interest_period');
    }
}
