<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\EmploymentHistory;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** The employment lifecycle's exit, and its two different ways back in. */
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

    /** The open role every employee created through the service starts with. */
    private function openRole(string $title = 'Staff', string $start = '2020-01-01'): EmploymentHistory
    {
        return EmploymentHistory::create([
            'employee_id' => $this->employee->id,
            'title' => $title,
            'start_date' => $start,
            'end_date' => null,
            'status' => 'current',
        ]);
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

    /** Needs a way to FIND a former employee — the directory hides soft-deleted rows. */
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

    /**
     * Searching the former roster must stay INSIDE the former roster. The search clauses are
     * OR'd together, so without their own group the `deleted_at is not null` filter only guards
     * the first branch and a job-title match drags active employees into the "former" list —
     * where the only actions offered are rehire and reinstate.
     */
    public function test_searching_the_former_roster_does_not_leak_active_employees(): void
    {
        $this->separate('2026-06-30');

        // An ACTIVE employee sharing the separated one's job title — the OR branch that leaks.
        Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2021-01-01',
            'employment_status' => 'regular',
            'base_salary' => 25000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees?status=former&search=Staff')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->employee->id);
    }

    /** The same guard for employee_no, the other unguarded OR branch. */
    public function test_searching_the_former_roster_by_employee_no_does_not_leak(): void
    {
        $this->separate('2026-06-30');

        Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Analyst', 'hire_date' => '2021-01-01',
            'employment_status' => 'regular',
            'base_salary' => 25000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees?status=former&search=E-00')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->employee->id);
    }

    /** Grouping the OR branches must not break the search itself. */
    public function test_the_active_directory_is_still_searchable(): void
    {
        Employee::create([
            'user_id' => User::factory()->create(['name' => 'Maria Santos'])->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Analyst', 'hire_date' => '2021-01-01',
            'employment_status' => 'regular',
            'base_salary' => 25000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $hr = $this->hrUser();

        // by name (through the user relation)
        $this->actingAs($hr)->getJson('/api/v1/hr/employees?search=Maria')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.job_title', 'Analyst');

        // by job title (a top-level column)
        $this->actingAs($hr)->getJson('/api/v1/hr/employees?search=Staff')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->employee->id);
    }

    // --- the employment-history timeline --------------------------------------------------------

    /**
     * Only a job-title change used to move this timeline, so the one transition that ends every
     * employment was the one it did not record: the record read "Left on 30 June" in the header and
     * "Present · current" in the role below it, at the same time.
     */
    public function test_separating_closes_the_open_role_on_the_last_working_day(): void
    {
        $role = $this->openRole();

        $this->service()->separate($this->employee, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-06-30',
            'reason' => 'Resigned',
        ]);

        $role->refresh();
        $this->assertSame('2026-06-30', $role->end_date->toDateString(), 'not the date HR filled it in');
        $this->assertSame('separated', $role->status);
        $this->assertSame('Resigned', $role->reason);
    }

    /** A role that ends before it begins is worse than an imprecise one. */
    public function test_a_backdated_last_day_never_inverts_the_role_it_closes(): void
    {
        $role = $this->openRole('Senior Staff', '2026-05-01');

        $this->service()->separate($this->employee, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-03-31',
        ]);

        $this->assertSame('2026-05-01', $role->refresh()->end_date->toDateString());
    }

    /** Reinstatement's claim is that the ending never happened, so the phantom exit goes too. */
    public function test_reinstating_reopens_the_role_the_separation_closed(): void
    {
        $role = $this->openRole();
        $this->separate('2026-06-30');

        $this->service()->reinstate(Employee::withTrashed()->find($this->employee->id));

        $role->refresh();
        $this->assertNull($role->end_date);
        $this->assertSame('current', $role->status);
        $this->assertSame(1, EmploymentHistory::count(), 'reinstatement adds no new stint');
    }

    /** A role closed by a promotion is a real ending — reinstating must not resurrect that job. */
    public function test_reinstating_leaves_a_role_closed_by_a_promotion_alone(): void
    {
        $old = $this->openRole('Junior Staff', '2020-01-01');
        $old->update(['end_date' => '2024-01-01', 'status' => 'promoted']);
        $current = $this->openRole('Staff', '2024-01-02');
        $this->separate('2026-06-30');

        $this->service()->reinstate(Employee::withTrashed()->find($this->employee->id));

        $this->assertSame('promoted', $old->refresh()->status);
        $this->assertSame('2024-01-01', $old->end_date->toDateString());
        $this->assertSame('current', $current->refresh()->status, 'only the separated one reopens');
    }

    /** A rehire is a new relationship, so it is a new entry — the break stays visible. */
    public function test_rehiring_opens_a_new_role_and_leaves_the_old_one_closed(): void
    {
        $old = $this->openRole();
        $this->separate('2026-06-30');

        $this->service()->rehire(Employee::withTrashed()->find($this->employee->id), '2026-09-01');

        $this->assertSame('2026-06-30', $old->refresh()->end_date->toDateString());
        $this->assertSame(2, EmploymentHistory::where('employee_id', $this->employee->id)->count());

        $new = EmploymentHistory::where('employee_id', $this->employee->id)
            ->whereNull('end_date')->firstOrFail();
        $this->assertSame('2026-09-01', $new->start_date->toDateString());
        $this->assertSame('current', $new->status);
        $this->assertSame('Staff', $new->title, 'the role they come back into');
    }

    /**
     * Anyone separated before separations closed the timeline still has an open role. Rehiring them
     * must not leave two stints both claiming to be current.
     */
    public function test_rehiring_a_record_whose_timeline_was_left_open_closes_it_first(): void
    {
        $stale = $this->openRole();
        $this->separate('2026-06-30');
        $stale->update(['end_date' => null, 'status' => 'current']); // as the old code left it

        $this->service()->rehire(Employee::withTrashed()->find($this->employee->id), '2026-09-01');

        $this->assertSame('2026-06-30', $stale->refresh()->end_date->toDateString());
        $this->assertSame(
            1,
            EmploymentHistory::where('employee_id', $this->employee->id)->whereNull('end_date')->count(),
            'exactly one current role',
        );
    }

    /** A record with no timeline at all (predates the feature) must not blow up on the way out. */
    public function test_separating_a_record_with_no_timeline_is_a_no_op(): void
    {
        $this->separate('2026-06-30');

        $this->assertSame(0, EmploymentHistory::count());
    }

    /**
     * The directory's "Former staff" roster links every card at this route, and Reinstate/Rehire are
     * only reachable from the record it opens — so without `withTrashed()` on the read, every one of
     * those cards was a dead link and the way back existed on the API alone.
     */
    public function test_a_former_employees_record_can_still_be_opened(): void
    {
        $this->openRole();
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/employees/{$this->employee->id}")
            ->assertOk()
            ->assertJsonPath('data.is_separated', true)
            ->assertJsonPath('data.separation_date', '2026-06-30')
            ->assertJsonPath('data.employment_history.0.end_date', '2026-06-30')
            ->assertJsonPath('data.employment_history.0.status', 'separated');
    }

    /** The read is widened, the write is not — an archived record is read-only. */
    public function test_a_former_employees_record_still_cannot_be_edited(): void
    {
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/employees/{$this->employee->id}", ['job_title' => 'Ghost'])
            ->assertNotFound();
    }

    /** End to end, through the resource the Job tab reads. */
    public function test_the_timeline_the_job_tab_reads_shows_both_stints_after_a_rehire(): void
    {
        $this->openRole();
        $this->separate('2026-06-30');

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/rehire", ['hire_date' => '2026-09-01'])
            ->assertOk()
            // The relation orders the open role first, which is the order the tab renders.
            ->assertJsonCount(2, 'data.employment_history')
            ->assertJsonPath('data.employment_history.0.start_date', '2026-09-01')
            ->assertJsonPath('data.employment_history.0.end_date', null)
            ->assertJsonPath('data.employment_history.1.end_date', '2026-06-30')
            ->assertJsonPath('data.employment_history.1.status', 'separated');
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
