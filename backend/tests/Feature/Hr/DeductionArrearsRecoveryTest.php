<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
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

/** Deductions a thin payslip could not collect are recovered later. */
class DeductionArrearsRecoveryTest extends HrTestCase
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

    /** A prior period that went short, written directly. */
    private function priorShortfall(array $breakdown, string $start = '2026-02-01', string $end = '2026-02-15'): Payslip
    {
        $run = PayrollRun::create([
            'cycle_start' => $start, 'cycle_end' => $end, 'frequency' => 'semi_monthly',
            'type' => 'regular', 'status' => 'completed', 'run_at' => now(),
        ]);

        return Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'base_salary' => 500, 'gross' => 500,
            'total_deductions' => 500, 'net_pay' => 0, 'status' => 'disbursed',
            'deduction_carryover' => array_sum($breakdown),
            'deduction_carryover_breakdown' => $breakdown,
        ]);
    }

    private function workedMarch(): void
    {
        $tz = config('app.timezone_local');
        for ($d = Carbon::parse('2026-03-01', $tz); $d->lte(Carbon::parse('2026-03-15', $tz)); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function runMarch(): PayrollRun
    {
        return app(PayrollService::class)->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
    }

    private function marchSlip(): Payslip
    {
        return Payslip::where('employee_id', $this->employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('cycle_start', '2026-03-01'))
            ->firstOrFail();
    }

    private function outstanding(): array
    {
        return app(PayrollService::class)->deductionArrearsFor([$this->employee->id])[$this->employee->id] ?? [];
    }

    // --- it is recovered -------------------------------------------------------------------

    public function test_an_earlier_shortfall_is_recovered_from_the_next_payslip(): void
    {
        $this->priorShortfall(['sss' => 800.0, 'philhealth' => 200.0]);
        $this->assertSame(['sss' => 800.0, 'philhealth' => 200.0], $this->outstanding());

        $this->workedMarch();
        $this->runMarch();

        $slip = $this->marchSlip();
        $this->assertSame(1000.0, (float) $slip->arrears_recovered, 'the arrears were not recovered');
        $this->assertEquals(['sss' => 800, 'philhealth' => 200], $slip->arrears_recovered_breakdown);
        $this->assertSame([], $this->outstanding(), 'the balance did not clear');
    }

    /** Recovered money reaches the columns the remittance report sums — it IS remitted this period. */
    public function test_recovered_arrears_are_added_to_the_obligation_columns(): void
    {
        $this->priorShortfall(['sss' => 800.0]);
        $this->workedMarch();
        $this->runMarch();

        $slip = $this->marchSlip();
        $ownSss = (float) $slip->sss - 800.0;

        $this->assertGreaterThan(0, $ownSss, 'this period should still charge its own SSS');
        $this->assertSame(
            round((float) $slip->sss + (float) $slip->philhealth + (float) $slip->pagibig
                + (float) $slip->withholding_tax + (float) $slip->other_deductions, 2),
            round((float) $slip->total_deductions, 2),
            'the components must still sum to the total (FIN-053 must not regress)',
        );
    }

    /** A payslip with no room recovers nothing, and the debt survives. */
    public function test_a_payslip_with_no_room_recovers_nothing(): void
    {
        $this->priorShortfall(['sss' => 800.0]);
        // No March attendance → every workday unpaid → gross 0 → nothing to deduct from.
        $this->runMarch();

        $outstanding = $this->outstanding();

        $this->assertSame(0.0, (float) $this->marchSlip()->arrears_recovered, 'nothing could be recovered');
        $this->assertGreaterThanOrEqual(800.0, $outstanding['sss'], 'the original debt must survive');
        $this->assertGreaterThan(
            800.0,
            array_sum($outstanding),
            'March could not pay its own dues either, so the balance should have grown',
        );
    }

    /** Only what there is room for, and the remainder stays outstanding. */
    public function test_a_partial_recovery_leaves_the_remainder_outstanding(): void
    {
        // Far more than a fortnight's pay can absorb.
        $this->priorShortfall(['sss' => 90000.0]);
        $this->workedMarch();
        $this->runMarch();

        $slip = $this->marchSlip();
        $recovered = (float) $slip->arrears_recovered;

        $this->assertGreaterThan(0, $recovered, 'some of it should have been recovered');
        $this->assertLessThan(90000.0, $recovered, 'it cannot all fit in one payslip');
        $this->assertSame(0.0, (float) $slip->net_pay, 'recovery may take net to zero but never below');
        $this->assertSame(round(90000.0 - $recovered, 2), $this->outstanding()['sss']);
    }

    // --- the ways this could be got wrong ---------------------------------------------------

    /**
     * THE REGRESSION THAT MATTERS. The balance is derived, so a re-run must recover the same amount
     * — not read its own prior recovery as "already settled" and stop, and not collect twice.
     */
    public function test_re_running_recovers_the_same_amount_once(): void
    {
        $this->priorShortfall(['sss' => 800.0]);
        $this->workedMarch();

        $this->runMarch();
        $first = (float) $this->marchSlip()->arrears_recovered;

        $this->runMarch();
        $this->runMarch();

        $this->assertSame($first, (float) $this->marchSlip()->arrears_recovered, 'recovery drifted across re-runs');
        $this->assertSame([], $this->outstanding(), 'the debt must be settled exactly once');
    }

    /** Statutory before tax before benefit shares — the priority the audit specifies. */
    public function test_recovery_follows_the_stated_priority_order(): void
    {
        // More than one payslip can absorb, so the order decides what gets taken.
        $this->priorShortfall([
            'other_deductions' => 40000.0,
            'withholding_tax' => 40000.0,
            'sss' => 5000.0,
        ]);
        $this->workedMarch();
        $this->runMarch();

        $recovered = $this->marchSlip()->arrears_recovered_breakdown ?? [];

        $this->assertEquals(5000, $recovered['sss'] ?? 0, 'SSS must be recovered first, in full');
        $this->assertArrayNotHasKey(
            'other_deductions',
            $recovered,
            'benefit shares must not be recovered before tax',
        );
    }

    /** Nothing outstanding must leave the ordinary payslip byte-for-byte unchanged. */
    public function test_an_employee_with_no_arrears_is_unaffected(): void
    {
        $this->workedMarch();
        $this->runMarch();

        $slip = $this->marchSlip();
        $this->assertSame(0.0, (float) $slip->arrears_recovered);
        $this->assertNull($slip->arrears_recovered_breakdown);
    }

    // --- the view ----------------------------------------------------------------------------

    public function test_hr_can_see_who_still_owes_what(): void
    {
        $this->priorShortfall(['sss' => 800.0, 'withholding_tax' => 150.0]);

        $this->actingAsHr()
            ->getJson('/api/v1/hr/deduction-arrears')
            ->assertOk()
            ->assertJsonPath('data.0.employee.name', $this->employee->user->name)
            ->assertJsonPath('data.0.total_outstanding', 950)
            ->assertJsonPath('data.0.employee.separated', false);
    }

    public function test_a_settled_employee_disappears_from_the_view(): void
    {
        $this->priorShortfall(['sss' => 800.0]);
        $this->workedMarch();
        $this->runMarch();

        $this->actingAsHr()
            ->getJson('/api/v1/hr/deduction-arrears')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
