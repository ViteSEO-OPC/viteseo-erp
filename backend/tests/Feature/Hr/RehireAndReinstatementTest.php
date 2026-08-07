<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * MF-18 — the employment lifecycle's exit, and its two different ways back in.
 *
 * A separation was `separation_type` + a soft delete with no documented reversal. It was worse than
 * that: NOTHING OUTSIDE `AttritionDemoSeeder` EVER WROTE A SEPARATION, so `separation_date` — the
 * column `runFinalPay()` refuses to work without, and the one attendance proration and leave accrual
 * read as the employment end — was never set by any path a user could reach. Final pay (FIN-014) was
 * built and unreachable.
 *
 * REINSTATEMENT AND REHIRE ARE DIFFERENT EVENTS and the tests are split on that line. Reinstatement
 * says the separation should not have happened, so service is continuous: hire date, leave credits and
 * loan authorisations all stand. Rehire says this is a new relationship: tenure restarts, leave is
 * seeded fresh, and an outstanding loan drops back to `pending_authorization` because Art. 113 wants
 * the employee's consent for the deduction actually being made — not consent they gave for a job they
 * no longer held. Getting these the same way round would reset the tenure of someone whose dismissal
 * was reversed, and wipe leave they had earned.
 */
class RehireAndReinstatementTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->vacation = LeaveType::create([
            'name' => 'Vacation', 'code' => 'VL', 'is_paid' => true, 'days_per_year' => 12,
        ]);
        LeaveTypeAllotment::create([
            'leave_type_id' => $this->vacation->id,
            'employment_status' => 'regular',
            'days_per_year' => 12,
        ]);
    }

    private function service(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    private function separate(string $lastDay = '2026-06-30', string $type = 'voluntary'): Employee
    {
        return $this->service()->separate($this->employee, [
            'separation_type' => $type,
            'separation_date' => $lastDay,
        ]);
    }

    // --- separation -----------------------------------------------------------------------------

    /**
     * THE ONE THAT UNBLOCKS FINAL PAY. `separation_date` is what `runFinalPay()` requires and what
     * nothing in the app used to write.
     */
    public function test_separating_records_the_last_working_day(): void
    {
        $this->separate('2026-06-30');

        $fresh = Employee::withTrashed()->find($this->employee->id);
        $this->assertSame('2026-06-30', $fresh->separation_date->toDateString());
        $this->assertSame('voluntary', $fresh->separation_type);
        $this->assertTrue($fresh->trashed(), 'the record is archived too');
    }

    /**
     * `deleted_at` is deliberately pinned to the last working day: the attrition report buckets
     * quarters on it while payroll prorates on `separation_date`, so they must not disagree.
     */
    public function test_the_archive_stamp_matches_the_separation_date(): void
    {
        $this->separate('2026-06-30');

        $fresh = Employee::withTrashed()->find($this->employee->id);
        $this->assertSame('2026-06-30', $fresh->deleted_at->toDateString());
    }

    public function test_separating_twice_is_refused(): void
    {
        $this->separate();

        $this->expectException(ValidationException::class);
        $this->service()->separate(
            Employee::withTrashed()->find($this->employee->id),
            ['separation_type' => 'voluntary', 'separation_date' => '2026-07-31'],
        );
    }

    public function test_the_separation_date_is_required_over_http(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/separate", [
                'separation_type' => 'voluntary',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('separation_date');
    }

    public function test_an_unknown_separation_type_is_refused(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/separate", [
                'separation_type' => 'retired',
                'separation_date' => '2026-06-30',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('separation_type');
    }

    // --- reinstatement (continuous service) -----------------------------------------------------

    public function test_reinstating_restores_the_record(): void
    {
        $this->separate();

        $result = $this->service()->reinstate(Employee::withTrashed()->find($this->employee->id));

        $this->assertFalse($result->trashed());
        $this->assertNull($result->separation_type);
        $this->assertNull($result->separation_date);
    }

    /** Continuous service is the whole point — the hire date must not move. */
    public function test_reinstating_keeps_the_original_hire_date(): void
    {
        $this->separate();

        $result = $this->service()->reinstate(Employee::withTrashed()->find($this->employee->id));

        $this->assertSame('2020-01-01', $result->hire_date->toDateString());
        $this->assertNull($result->previous_hire_date, 'no break in service to record');
    }

    /** Leave earned under a relationship now treated as unbroken stays earned. */
    public function test_reinstating_leaves_the_balance_untouched(): void
    {
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->vacation->id,
            'year' => 2026, 'entitled_days' => 12, 'used_days' => 5,
        ]);
        $this->separate();

        $this->service()->reinstate(Employee::withTrashed()->find($this->employee->id));

        $balance = LeaveBalance::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame(12.0, (float) $balance->entitled_days);
        $this->assertSame(5.0, (float) $balance->used_days);
        $this->assertSame(1, LeaveBalance::count(), 'and no second row was seeded');
    }

    /** The employee's Art. 113 consent was never withdrawn, so collection resumes. */
    public function test_reinstating_keeps_a_loan_authorised(): void
    {
        $loan = EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'active', 'authorized_at' => now(),
        ]);
        $this->separate();

        $this->service()->reinstate(Employee::withTrashed()->find($this->employee->id));

        $this->assertSame('active', $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->authorized_at);
    }

    public function test_reinstating_an_active_employee_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->reinstate($this->employee);
    }

    // --- rehire (new relationship) --------------------------------------------------------------

    public function test_rehiring_restarts_the_hire_date_and_keeps_the_old_one(): void
    {
        $this->separate('2026-06-30');

        $result = $this->service()->rehire(
            Employee::withTrashed()->find($this->employee->id),
            '2026-09-01',
        );

        $this->assertFalse($result->trashed());
        $this->assertSame('2026-09-01', $result->hire_date->toDateString());
        $this->assertSame('2020-01-01', $result->previous_hire_date->toDateString());
        $this->assertNull($result->separation_date);
    }

    /** Fresh credits from the allotment defaults, exactly as a new hire gets. */
    public function test_rehiring_seeds_leave_for_the_current_year(): void
    {
        $this->separate('2026-06-30');
        LeaveBalance::where('employee_id', $this->employee->id)->delete();

        $this->service()->rehire(Employee::withTrashed()->find($this->employee->id), '2026-09-01');

        $balance = LeaveBalance::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame(12.0, (float) $balance->entitled_days, 'from the regular-status allotment');
        $this->assertSame(0.0, (float) $balance->used_days);
    }

    /**
     * A rehire in the SAME calendar year already has balance rows, and seeding must not duplicate
     * them or reset days the previous stint used (final pay may have converted them to cash).
     */
    public function test_rehiring_in_the_same_year_does_not_duplicate_or_reset_balances(): void
    {
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->vacation->id,
            'year' => 2026, 'entitled_days' => 12, 'used_days' => 5,
        ]);
        $this->separate('2026-06-30');

        $this->service()->rehire(Employee::withTrashed()->find($this->employee->id), '2026-09-01');

        $this->assertSame(1, LeaveBalance::count());
        $this->assertSame(
            5.0,
            (float) LeaveBalance::first()->used_days,
            'days already taken (or paid out at final pay) must not be handed back',
        );
    }

    /**
     * THE ART. 113 ONE. An outstanding loan must not resume collecting against the new
     * relationship's wages on consent given for the old one.
     */
    public function test_rehiring_sends_an_outstanding_loan_back_for_fresh_consent(): void
    {
        $loan = EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'active', 'authorized_at' => now(),
        ]);
        $this->separate('2026-06-30');

        $this->service()->rehire(Employee::withTrashed()->find($this->employee->id), '2026-09-01');

        $fresh = $loan->fresh();
        $this->assertSame('pending_authorization', $fresh->status, 'so collectForPeriod() skips it');
        $this->assertNull($fresh->authorized_at, 'the old consent record is cleared, not reused');
    }

    /** A loan already settled at final pay has nothing owing, so it is left alone. */
    public function test_a_settled_loan_is_not_reopened_by_a_rehire(): void
    {
        $loan = EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-01-01', 'status' => 'settled', 'authorized_at' => now(),
        ]);
        $this->separate('2026-06-30');

        $this->service()->rehire(Employee::withTrashed()->find($this->employee->id), '2026-09-01');

        $this->assertSame('settled', $loan->fresh()->status);
    }

    /** A start date on or before the last day is a correction, and reinstatement is that verb. */
    public function test_a_rehire_date_before_the_last_day_is_refused(): void
    {
        $this->separate('2026-06-30');

        $this->expectException(ValidationException::class);
        $this->service()->rehire(Employee::withTrashed()->find($this->employee->id), '2026-06-15');
    }

    public function test_rehiring_an_active_employee_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->rehire($this->employee, '2026-09-01');
    }

    // --- the HTTP surface -----------------------------------------------------------------------

    /** The route has to resolve a TRASHED employee, or the reversal is unreachable. */
    public function test_hr_can_rehire_over_http(): void
    {
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/rehire", [
                'hire_date' => '2026-09-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.hire_date', '2026-09-01')
            ->assertJsonPath('data.previous_hire_date', '2020-01-01')
            ->assertJsonPath('data.is_separated', false);
    }

    public function test_hr_can_reinstate_over_http(): void
    {
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/reinstate")
            ->assertOk()
            ->assertJsonPath('data.hire_date', '2020-01-01')
            ->assertJsonPath('data.is_separated', false);
    }

    public function test_an_employee_cannot_separate_anyone(): void
    {
        $this->actingAs($this->employee->user)
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/separate", [
                'separation_type' => 'voluntary',
                'separation_date' => '2026-06-30',
            ])
            ->assertForbidden();
    }

    /** MF-18 needs a way to FIND a former employee — the directory hides soft-deleted rows. */
    public function test_former_employees_can_be_listed(): void
    {
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees?status=former')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->employee->id)
            ->assertJsonPath('data.0.is_separated', true)
            ->assertJsonPath('data.0.separation_date', '2026-06-30');
    }

    /** The default view is unchanged: active only. */
    public function test_the_directory_still_hides_former_employees_by_default(): void
    {
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Final pay becomes reachable once a separation exists — the point of the whole item. */
    public function test_final_pay_is_reachable_after_a_separation(): void
    {
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/final-pay", [
                'reason' => 'Resignation effective 30 June 2026',
                'separation_pay' => 10000,
            ])
            ->assertSuccessful();
    }
}
