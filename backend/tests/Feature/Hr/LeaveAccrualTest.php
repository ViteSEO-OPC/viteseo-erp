<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Services\LeaveAccrualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** (`R` half) / — leave that accrues, carries over and expires. */
class LeaveAccrualTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-15 09:00', config('app.timezone_local')));

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function type(array $attrs = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'name' => 'Vacation', 'code' => 'VL', 'is_paid' => true,
            'days_per_year' => 12, 'accrual_mode' => 'monthly',
        ], $attrs));
    }

    private function balance(LeaveType $type, int $year = 2026): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $this->employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->first();
    }

    private function service(): LeaveAccrualService
    {
        return app(LeaveAccrualService::class);
    }

    // --- opt-in ---------------------------------------------------------------------------------

    /** THE GUARD ON THE MIGRATION: a type left alone must be left alone. */
    public function test_a_type_with_accrual_off_is_never_touched(): void
    {
        $type = $this->type(['accrual_mode' => 'none']);

        $this->assertSame(0, $this->service()->accrue(2026));
        $this->assertNull($this->balance($type), 'no row should be invented for a hand-managed type');
    }

    /** An existing hand-entered balance on a non-accruing type stays exactly as HR left it. */
    public function test_a_hand_entered_balance_survives_an_accrual_run(): void
    {
        $type = $this->type(['accrual_mode' => 'none']);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2026, 'entitled_days' => 15, 'used_days' => 3,
        ]);

        $this->service()->accrue(2026);

        $this->assertSame(15.0, (float) $this->balance($type)->entitled_days);
    }

    // --- monthly accrual ------------------------------------------------------------------------

    public function test_monthly_accrual_credits_the_elapsed_months(): void
    {
        $type = $this->type(); // 12 days/year = 1.0 per month

        $this->service()->accrue(2026, '2026-06-15');

        $balance = $this->balance($type);
        $this->assertSame(6.0, (float) $balance->accrued_days, 'January through June');
        $this->assertSame(6.0, (float) $balance->entitled_days);
        $this->assertSame(6, (int) $balance->accrued_through_month);
    }

    public function test_it_creates_the_balance_row_when_none_exists(): void
    {
        $type = $this->type();

        $this->assertNull($this->balance($type));
        $this->service()->accrue(2026, '2026-03-31');
        $this->assertNotNull($this->balance($type), 'HR should not have to pre-create rows');
    }

    /** A fractional rate has to survive decimal(5,1) — 15 ÷ 12 = 1.25/month. */
    public function test_a_fractional_monthly_rate_accrues(): void
    {
        $type = $this->type(['days_per_year' => 15]);

        $this->service()->accrue(2026, '2026-04-30'); // 4 months × 1.25 = 5.0

        $this->assertSame(5.0, (float) $this->balance($type)->accrued_days);
    }

    /** THE ONE THAT PROTECTS THE PAYROLL. Running twice must not credit twice. */
    public function test_running_twice_credits_the_same_days(): void
    {
        $type = $this->type();

        $this->service()->accrue(2026, '2026-06-15');
        $this->service()->accrue(2026, '2026-06-15');
        $this->service()->accrue(2026, '2026-06-15');

        $this->assertSame(6.0, (float) $this->balance($type)->accrued_days);
        $this->assertSame(1, LeaveBalance::count(), 'and no duplicate rows');
    }

    /** A re-run that changes nothing must not even touch the row. */
    public function test_a_no_op_run_reports_nothing_written(): void
    {
        $type = $this->type();

        $this->assertSame(1, $this->service()->accrue(2026, '2026-06-15'));
        $this->assertSame(0, $this->service()->accrue(2026, '2026-06-15'));
        $this->assertNotNull($type->fresh());
    }

    /** A missed month is caught up on the next run, not skipped. */
    public function test_a_missed_month_is_caught_up(): void
    {
        $type = $this->type();

        $this->service()->accrue(2026, '2026-03-31'); // 3
        $this->service()->accrue(2026, '2026-06-15'); // straight to 6, not 4

        $this->assertSame(6.0, (float) $this->balance($type)->accrued_days);
    }

    public function test_nothing_accrues_before_the_year_starts(): void
    {
        $this->type();

        $this->assertSame(0, $this->service()->accrue(2027, '2026-06-15'));
    }

    // --- pro-rating -----------------------------------------------------------------------------

    /** A mid-year hire accrues from their hire month inclusive, not the whole year. */
    public function test_a_mid_year_hire_is_prorated(): void
    {
        $type = $this->type();
        $this->employee->update(['hire_date' => '2026-04-20']);

        $this->service()->accrue(2026, '2026-06-15'); // April, May, June = 3

        $this->assertSame(3.0, (float) $this->balance($type)->accrued_days);
    }

    public function test_prorating_can_be_switched_off(): void
    {
        $type = $this->type(['prorate_first_year' => false]);
        $this->employee->update(['hire_date' => '2026-04-20']);

        $this->service()->accrue(2026, '2026-06-15');

        $this->assertSame(6.0, (float) $this->balance($type)->accrued_days, 'the full elapsed year');
    }

    public function test_someone_hired_next_year_accrues_nothing(): void
    {
        $type = $this->type();
        $this->employee->update(['hire_date' => '2027-01-01']);

        $this->service()->accrue(2026, '2026-06-15');

        $this->assertNull($this->balance($type));
    }

    /** A leaver accrues through their final month, so final pay can convert what they earned. */
    public function test_a_leaver_accrues_through_their_last_month(): void
    {
        $type = $this->type();
        $this->employee->update(['separation_date' => '2026-04-30']);

        $this->service()->accrue(2026, '2026-06-15');

        $this->assertSame(4.0, (float) $this->balance($type)->accrued_days);
    }

    /**
     * The accrual rate must come from the SAME place a new hire's balance does: the
     * per-employment-status allotment first, the type's own figure only as a fallback. Reading only
     * the type would accrue a probationary employee at a regular employee's rate.
     */
    public function test_the_per_status_allotment_beats_the_types_own_figure(): void
    {
        $type = $this->type(['days_per_year' => 12]); // 1.0/month
        $this->employee->update(['employment_status' => 'probationary']);

        LeaveTypeAllotment::create([
            'leave_type_id' => $type->id,
            'employment_status' => 'probationary',
            'days_per_year' => 6, // 0.5/month
        ]);

        $this->service()->accrue(2026, '2026-06-15');

        $this->assertSame(3.0, (float) $this->balance($type)->accrued_days, '6 months x 0.5');
    }

    /** A status with no allotment row falls back to the leave type's figure. */
    public function test_a_status_without_an_allotment_uses_the_type_figure(): void
    {
        $type = $this->type(['days_per_year' => 12]);
        $this->employee->update(['employment_status' => 'regular']);

        LeaveTypeAllotment::create([
            'leave_type_id' => $type->id,
            'employment_status' => 'probationary',
            'days_per_year' => 6,
        ]);

        $this->service()->accrue(2026, '2026-06-15');

        $this->assertSame(6.0, (float) $this->balance($type)->accrued_days);
    }

    // --- annual mode ----------------------------------------------------------------------------

    public function test_annual_accrual_credits_the_whole_year_at_once(): void
    {
        $type = $this->type(['accrual_mode' => 'annual']);

        $this->service()->accrue(2026, '2026-01-05');

        $this->assertSame(12.0, (float) $this->balance($type)->accrued_days);
    }

    // --- carry-over -----------------------------------------------------------------------------

    /** No cap set = everything unused carries. Nothing is forfeited by default. */
    public function test_unused_days_carry_forward_uncapped_by_default(): void
    {
        $type = $this->type();
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 4,
        ]);

        $this->service()->rolloverInto(2026);

        $balance = $this->balance($type);
        $this->assertSame(8.0, (float) $balance->carried_days, '12 − 4');
        $this->assertSame(8.0, (float) $balance->entitled_days);
        $this->assertNull($balance->carried_expires_on, 'no expiry configured means none applies');
    }

    public function test_the_cap_limits_what_carries(): void
    {
        $type = $this->type(['carry_over_cap_days' => 5]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 2,
        ]);

        $this->service()->rolloverInto(2026);

        $this->assertSame(5.0, (float) $this->balance($type)->carried_days, '10 unused, capped at 5');
    }

    /** A zero cap is "use it or lose it" — expressible, but only when HR says so. */
    public function test_a_zero_cap_carries_nothing(): void
    {
        $type = $this->type(['carry_over_cap_days' => 0]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 0,
        ]);

        $this->service()->rolloverInto(2026);

        $this->assertNull($this->balance($type), 'nothing to carry means no row to create');
    }

    public function test_rollover_is_idempotent(): void
    {
        $type = $this->type();
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 4,
        ]);

        $this->service()->rolloverInto(2026);
        $this->service()->rolloverInto(2026);

        $this->assertSame(8.0, (float) $this->balance($type)->carried_days, 'carried, not stacked');
    }

    /** Accrual and carry-over compose: the total is the sum of both components. */
    public function test_accrual_adds_on_top_of_carried_days(): void
    {
        $type = $this->type();
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 9,
        ]);

        $this->service()->rolloverInto(2026);
        $this->service()->accrue(2026, '2026-06-15');

        $balance = $this->balance($type);
        $this->assertSame(3.0, (float) $balance->carried_days);
        $this->assertSame(6.0, (float) $balance->accrued_days);
        $this->assertSame(9.0, (float) $balance->entitled_days, '3 carried + 6 accrued');
    }

    // --- expiry ---------------------------------------------------------------------------------

    public function test_carried_days_expire_on_the_configured_date(): void
    {
        $type = $this->type(['carry_over_expiry_months' => 3]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 4,
        ]);

        $this->service()->rolloverInto(2026);
        $this->assertSame('2026-04-01', $this->balance($type)->carried_expires_on->toDateString());

        // Mid-June is past it.
        $this->service()->expireCarriedDays('2026-06-15');

        $this->assertSame(0.0, (float) $this->balance($type)->carried_days);
    }

    public function test_carried_days_survive_until_their_expiry(): void
    {
        $type = $this->type(['carry_over_expiry_months' => 6]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 4,
        ]);

        $this->service()->rolloverInto(2026);
        $this->service()->expireCarriedDays('2026-06-15'); // expiry is 2026-07-01

        $this->assertSame(8.0, (float) $this->balance($type)->carried_days);
    }

    /**
     * EXPIRY MUST NOT CLAW BACK LEAVE ALREADY TAKEN. The employee was off work; that cannot be
     * undone. Only the unspent remainder can lapse, so the entitlement floors at `used_days`.
     */
    public function test_expiry_never_takes_back_days_already_used(): void
    {
        $type = $this->type(['carry_over_expiry_months' => 3]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 10, 'used_days' => 0,
        ]);

        $this->service()->rolloverInto(2026);
        // They spent 7 of the 10 carried days before it lapsed.
        $this->balance($type)->update(['used_days' => 7]);

        $this->service()->expireCarriedDays('2026-06-15');

        $balance = $this->balance($type);
        $this->assertSame(7.0, (float) $balance->entitled_days, 'floored at what was taken');
        $this->assertSame(
            0.0,
            (float) $balance->entitled_days - (float) $balance->used_days,
            'nothing left over, but never negative',
        );
    }

    /** Lowering the yearly entitlement must not push a balance negative either. */
    public function test_reducing_the_entitlement_never_goes_below_days_taken(): void
    {
        $type = $this->type();
        $this->service()->accrue(2026, '2026-06-15'); // 6 days
        $this->balance($type)->update(['used_days' => 6]);

        $type->update(['days_per_year' => 2]); // 1 day accrued by June

        $this->service()->accrue(2026, '2026-06-15');

        $this->assertSame(6.0, (float) $this->balance($type)->entitled_days);
    }

    // --- the command ----------------------------------------------------------------------------

    public function test_the_command_rolls_over_accrues_and_expires(): void
    {
        $type = $this->type(['carry_over_expiry_months' => 3]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2025, 'entitled_days' => 12, 'used_days' => 10,
        ]);

        $this->artisan('hr:leave-accrual', ['--as-of' => '2026-06-15'])
            ->assertExitCode(0);

        $balance = $this->balance($type);
        $this->assertSame(6.0, (float) $balance->accrued_days, 'Jan–Jun accrued');
        $this->assertSame(0.0, (float) $balance->carried_days, '2 carried, then lapsed in April');
        $this->assertSame(6.0, (float) $balance->entitled_days);
    }

    public function test_the_command_is_safe_to_run_twice(): void
    {
        $this->type();

        $this->artisan('hr:leave-accrual', ['--as-of' => '2026-06-15'])->assertExitCode(0);
        $this->artisan('hr:leave-accrual', ['--as-of' => '2026-06-15'])->assertExitCode(0);

        $this->assertSame(6.0, (float) LeaveBalance::first()->accrued_days);
    }
}
