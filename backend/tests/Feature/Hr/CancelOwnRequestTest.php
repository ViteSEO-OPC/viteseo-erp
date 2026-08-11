<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Models\Reimbursement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * Withdrawing a claim, payroll inquiry or concern you filed yourself.
 *
 * Leave and overtime already had this; these three did not, so the only escape from a mistaken
 * submission was to ask HR to DECLINE it — which writes a rejection against the employee instead
 * of a withdrawal by them. The two are different facts and the record has to keep them apart.
 *
 * The rule lives once in `Services\Concerns\CancelsOwnRequest`, so these tests exercise it through
 * all three endpoints rather than trusting that sharing a trait makes them behave alike.
 */
class CancelOwnRequestTest extends HrTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Employee $employee;

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
    }

    private function claim(string $status = 'pending'): Reimbursement
    {
        return Reimbursement::create([
            'employee_id' => $this->employee->id,
            'category' => 'travel',
            'amount' => 850.00,
            'expense_date' => '2026-08-03',
            'description' => 'Taxi to the client site',
            'status' => $status,
        ]);
    }

    private function inquiry(string $status = 'pending'): PayrollInquiry
    {
        return PayrollInquiry::create([
            'employee_id' => $this->employee->id,
            'subject' => 'Missing overtime',
            'topic' => 'overtime',
            'message' => 'My July overtime is not on the payslip.',
            'priority' => 'normal',
            'status' => $status,
        ]);
    }

    private function concern(string $status = 'pending', bool $anonymous = false): Concern
    {
        return Concern::create([
            'employee_id' => $this->employee->id,
            'reference_no' => 'CN-' . fake()->unique()->numberBetween(1000, 9999),
            'category' => 'workplace',
            'description' => 'The break room lock is broken.',
            'is_anonymous' => $anonymous,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    public function test_an_employee_withdraws_their_own_pending_claim(): void
    {
        Sanctum::actingAs($this->owner);
        $claim = $this->claim();

        $this->postJson("/api/v1/hr/reimbursements/{$claim->id}/cancel", [
            'reason' => 'Filed it twice by accident',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Filed it twice by accident');

        $claim->refresh();
        $this->assertSame($this->owner->id, $claim->cancelled_by);
        $this->assertNotNull($claim->cancelled_at);

        // The withdrawal must NOT masquerade as an HR decision.
        $this->assertNull($claim->review_note);
        $this->assertNull($claim->reviewed_by);
    }

    public function test_an_employee_withdraws_their_own_pending_inquiry(): void
    {
        Sanctum::actingAs($this->owner);
        $inquiry = $this->inquiry();

        $this->postJson("/api/v1/hr/me/payroll-inquiries/{$inquiry->id}/cancel", [
            'reason' => 'Found it — it was on the next payslip',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame($this->owner->id, $inquiry->fresh()->cancelled_by);
    }

    public function test_an_employee_withdraws_their_own_pending_concern(): void
    {
        Sanctum::actingAs($this->owner);
        $concern = $this->concern();

        $this->postJson("/api/v1/hr/me/concerns/{$concern->id}/cancel", [
            'reason' => 'Maintenance already fixed it',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame($this->owner->id, $concern->fresh()->cancelled_by);
    }

    public function test_the_reason_is_required(): void
    {
        Sanctum::actingAs($this->owner);
        $claim = $this->claim();

        $this->postJson("/api/v1/hr/reimbursements/{$claim->id}/cancel", ['reason' => '  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('pending', $claim->fresh()->status);
    }

    /** Once HR has acted, the way out is their decision — not a quiet withdrawal by the employee. */
    public function test_a_claim_already_decided_cannot_be_withdrawn(): void
    {
        Sanctum::actingAs($this->owner);
        $claim = $this->claim('approved');

        $this->postJson("/api/v1/hr/reimbursements/{$claim->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('approved', $claim->fresh()->status);
    }

    public function test_a_claim_scheduled_into_a_payroll_run_cannot_be_withdrawn(): void
    {
        Sanctum::actingAs($this->owner);
        $claim = $this->claim('scheduled');

        $this->postJson("/api/v1/hr/reimbursements/{$claim->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(422);

        $this->assertSame('scheduled', $claim->fresh()->status);
    }

    public function test_cancelling_twice_is_refused(): void
    {
        Sanctum::actingAs($this->owner);
        $claim = $this->claim();

        $this->postJson("/api/v1/hr/reimbursements/{$claim->id}/cancel", ['reason' => 'Mistake'])->assertOk();

        $this->postJson("/api/v1/hr/reimbursements/{$claim->id}/cancel", ['reason' => 'Again'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    /** The identity check is the point: one employee must not reach another's request. */
    public function test_another_employee_cannot_withdraw_someone_elses_claim(): void
    {
        $claim = $this->claim();

        $otherUser = User::factory()->create();
        $otherUser->assignRole('hr_employee');
        Employee::create([
            'user_id' => $otherUser->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'EMP-0002',
            'job_title' => 'Analyst',
            'hire_date' => '2026-02-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'employment_status' => 'regular',
        ]);

        Sanctum::actingAs($otherUser);

        $this->postJson("/api/v1/hr/reimbursements/{$claim->id}/cancel", ['reason' => 'Not mine'])
            ->assertForbidden();

        $this->assertSame('pending', $claim->fresh()->status);
    }

    /**
     * The canceller IS the reporter, so on an anonymous concern the name must not come back in
     * `cancelled_by` — that would hand over, in a different field, the identity the resource
     * withholds from `employee`.
     */
    public function test_an_anonymous_concern_does_not_leak_its_reporter_through_the_canceller(): void
    {
        Sanctum::actingAs($this->owner);
        $concern = $this->concern(anonymous: true);

        $this->postJson("/api/v1/hr/me/concerns/{$concern->id}/cancel", ['reason' => 'Sorted itself out'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.employee', null)
            ->assertJsonPath('data.cancelled_by', null);
    }

    public function test_a_named_concern_still_reports_who_withdrew_it(): void
    {
        Sanctum::actingAs($this->owner);
        $concern = $this->concern();

        $this->postJson("/api/v1/hr/me/concerns/{$concern->id}/cancel", ['reason' => 'Resolved informally'])
            ->assertOk()
            ->assertJsonPath('data.cancelled_by', $this->owner->name);
    }
}
