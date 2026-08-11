<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * Which leave types an employee is actually offered.
 *
 * Every type used to be listed for everyone, so a paid type the employee held no credits for
 * appeared in the picker and was then refused by the 422 on submit — which reads as a broken form,
 * not as "you are not entitled to this". Zero credits now means the type is not theirs.
 *
 * The rule has to survive two things that look like zero but are not:
 *
 *  - An UNLIMITED type (LWOP ships this way) has 0 days and no allotment by design — it is
 *    uncapped, so there is no entitlement to be zero, and it must always be filable.
 *  - SHARED mode gives every paid type one common pool, so a per-type number means nothing there.
 *
 * HR is exempt from all of it: the same endpoint feeds the Company → Leave Type config screen.
 */
class SelectableLeaveTypesTest extends HrTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Employee $employee;

    private LeaveType $annual;

    private LeaveType $sick;

    private LeaveType $lwop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $department = Department::create(['name' => 'Operations', 'code' => 'OPS']);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('hr_employee');

        $this->employee = Employee::create([
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-0001',
            'job_title' => 'Analyst',
            'hire_date' => '2026-01-05',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'employment_status' => 'regular',
        ]);

        $this->annual = LeaveType::create([
            'name' => 'Annual Leave', 'code' => 'AL', 'days_per_year' => 10, 'is_paid' => true,
        ]);
        $this->sick = LeaveType::create([
            'name' => 'Sick Leave', 'code' => 'SL', 'days_per_year' => 5, 'is_paid' => true,
        ]);
        $this->lwop = LeaveType::create([
            'name' => 'Unpaid Leave (LWOP)', 'code' => 'LWOP', 'days_per_year' => 0, 'is_paid' => false, 'is_unlimited' => true,
        ]);
    }

    private function allot(LeaveType $type, float $days): void
    {
        LeaveTypeAllotment::updateOrCreate(
            ['leave_type_id' => $type->id, 'employment_status' => 'regular'],
            ['days_per_year' => $days],
        );
    }

    /** @return array<int, string> the codes the caller is offered */
    private function offeredCodes(): array
    {
        $response = $this->getJson('/api/v1/hr/leave-types')->assertOk();

        return array_column($response->json('data'), 'code');
    }

    public function test_a_paid_type_with_a_zero_allotment_is_not_offered(): void
    {
        $this->allot($this->annual, 10);
        $this->allot($this->sick, 0);

        Sanctum::actingAs($this->owner);

        $codes = $this->offeredCodes();
        $this->assertContains('AL', $codes);
        $this->assertNotContains('SL', $codes);
    }

    /** The regression this rule must never cause: LWOP is 0 days and must still be filable. */
    public function test_unpaid_leave_is_always_offered_despite_having_no_allotment(): void
    {
        $this->allot($this->annual, 0);
        $this->allot($this->sick, 0);

        Sanctum::actingAs($this->owner);

        $codes = $this->offeredCodes();
        $this->assertSame(['LWOP'], $codes);
    }

    public function test_a_paid_type_with_no_allotment_row_at_all_is_not_offered(): void
    {
        $this->allot($this->annual, 10);
        // `sick` gets no row — unconfigured reads the same as zero.

        Sanctum::actingAs($this->owner);

        $this->assertNotContains('SL', $this->offeredCodes());
    }

    /** An individual grant beats the band default, so HR can give one person a type nobody else has. */
    public function test_a_personal_balance_overrides_a_zero_band_default(): void
    {
        $this->allot($this->sick, 0);

        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->sick->id,
            'year' => now(config('app.timezone_local'))->year,
            'entitled_days' => 3,
            'used_days' => 0,
        ]);

        Sanctum::actingAs($this->owner);

        $this->assertContains('SL', $this->offeredCodes());
    }

    /** And the reverse: a zero balance row is HR saying "not this person", so it wins too. */
    public function test_a_zero_personal_balance_overrides_a_positive_band_default(): void
    {
        $this->allot($this->sick, 5);

        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->sick->id,
            'year' => now(config('app.timezone_local'))->year,
            'entitled_days' => 0,
            'used_days' => 0,
        ]);

        Sanctum::actingAs($this->owner);

        $this->assertNotContains('SL', $this->offeredCodes());
    }

    /** Shared mode has one pool, so a per-type number decides nothing. */
    public function test_shared_mode_offers_every_paid_type_regardless_of_allotment(): void
    {
        LeaveSetting::current()->update(['allotment_mode' => LeaveSetting::MODE_SHARED]);

        $this->allot($this->annual, 0);
        $this->allot($this->sick, 0);

        Sanctum::actingAs($this->owner);

        $codes = $this->offeredCodes();
        $this->assertContains('AL', $codes);
        $this->assertContains('SL', $codes);
        $this->assertContains('LWOP', $codes);
    }

    /** HR configures these, so they must keep seeing the ones nobody is entitled to. */
    public function test_hr_still_sees_every_type(): void
    {
        $this->allot($this->annual, 0);
        $this->allot($this->sick, 0);

        Sanctum::actingAs($this->hrUser());

        $codes = $this->offeredCodes();
        $this->assertContains('AL', $codes);
        $this->assertContains('SL', $codes);
        $this->assertContains('LWOP', $codes);
    }

    /** The per-type breakdown drops the same rows the picker does. */
    public function test_zero_credit_balances_are_left_out_of_the_summary(): void
    {
        $year = now(config('app.timezone_local'))->year;

        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id,
            'year' => $year, 'entitled_days' => 10, 'used_days' => 2,
        ]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->sick->id,
            'year' => $year, 'entitled_days' => 0, 'used_days' => 0,
        ]);

        Sanctum::actingAs($this->owner);

        $rows = $this->getJson('/api/v1/hr/me/leave-balances')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('AL', $rows[0]['leave_type']['code']);
    }

    /**
     * The combination the `is_unlimited` flag exists to make possible: an unpaid type that is
     * nonetheless capped — "you may take at most N days of LWOP a year". Before the flag, unpaid
     * implied uncapped and this could not be expressed at all.
     */
    public function test_a_capped_unpaid_type_is_hidden_when_the_employee_has_no_days(): void
    {
        $this->lwop->update(['is_unlimited' => false]);
        $this->allot($this->annual, 10);
        $this->allot($this->lwop, 0);

        Sanctum::actingAs($this->owner);

        $this->assertNotContains('LWOP', $this->offeredCodes());
    }

    public function test_a_capped_unpaid_type_is_offered_once_it_has_days(): void
    {
        $this->lwop->update(['is_unlimited' => false]);
        $this->allot($this->lwop, 10);

        Sanctum::actingAs($this->owner);

        $this->assertContains('LWOP', $this->offeredCodes());
    }

    /** And the other new combination: unlimited PAID leave, offered with no allotment at all. */
    public function test_an_unlimited_paid_type_is_offered_without_any_allotment(): void
    {
        $this->annual->update(['is_unlimited' => true]);
        $this->allot($this->annual, 0);

        Sanctum::actingAs($this->owner);

        $this->assertContains('AL', $this->offeredCodes());
    }

    /**
     * Deleting a leave type must not take the employee's whole balance list down with it.
     *
     * WHAT HAPPENED. `deleteType()` only SOFT-deletes, so every `hr_leave_balances` row still
     * points at the type — but `belongsTo(LeaveType)` resolves a trashed parent to NULL, and
     * `LeaveBalanceResource` read `$this->leaveType->id` straight through it. One deleted type
     * therefore 500'd `/me/leave-balances` for every employee who had ever held that leave, and
     * the Work Schedule page rendered empty credit cards in BOTH allotment modes because the
     * request never returned at all.
     */
    public function test_deleting_a_leave_type_does_not_break_the_balance_list(): void
    {
        $year = now(config('app.timezone_local'))->year;

        foreach ([$this->annual, $this->sick] as $type) {
            LeaveBalance::create([
                'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
                'year' => $year, 'entitled_days' => 5, 'used_days' => 0,
            ]);
        }

        $this->sick->delete();

        Sanctum::actingAs($this->owner);

        $rows = $this->getJson('/api/v1/hr/me/leave-balances')->assertOk()->json('data');

        // The surviving type is listed; the deleted one is simply gone, not a broken row.
        $this->assertCount(1, $rows);
        $this->assertSame('AL', $rows[0]['leave_type']['code']);
    }

    /** Same guard on the HR-facing view of one person's balances. */
    public function test_hr_can_still_read_balances_after_a_type_is_deleted(): void
    {
        $year = now(config('app.timezone_local'))->year;

        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->sick->id,
            'year' => $year, 'entitled_days' => 5, 'used_days' => 0,
        ]);

        $this->sick->delete();

        Sanctum::actingAs($this->hrUser());

        $this->getJson("/api/v1/hr/employees/{$this->employee->id}/leave-balances")->assertOk();
    }
}
