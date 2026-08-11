<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\OvertimeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * Withdrawing the Work Schedule requests an employee files themselves.
 *
 * Leave could already be cancelled; half-day and early-out had no endpoint at all, and overtime's
 * was HR-only. So three of the four request types on that screen could only be undone by asking HR
 * to DECLINE them — a refusal on the record for what was a change of mind.
 *
 * Overtime keeps a split the other two do not need: the OWNER may withdraw a PENDING request, but
 * cancelling an APPROVED one stays HR-only, because an approved request whose period is already
 * paid raises an overpayment that only HR can settle.
 */
class CancelWorkScheduleRequestTest extends HrTestCase
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

    private function halfDay(string $status = 'pending'): HalfDayRequest
    {
        return HalfDayRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-14',
            'period' => 'morning',
            'reason' => 'Dentist',
            'status' => $status,
        ]);
    }

    private function earlyOut(string $status = 'pending'): EarlyOutRequest
    {
        return EarlyOutRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-14',
            'early_out_time' => '15:00',
            'reason' => 'Family errand',
            'status' => $status,
        ]);
    }

    private function overtime(string $status = 'pending'): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-14',
            'time_from' => '18:00',
            'time_to' => '20:00',
            'hours' => 2,
            'reason' => 'Month-end close',
            'status' => $status,
        ]);
    }

    private function otherEmployeeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('hr_employee');
        Employee::create([
            'user_id' => $user->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'EMP-0002',
            'job_title' => 'Analyst',
            'hire_date' => '2026-02-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'employment_status' => 'regular',
        ]);

        return $user;
    }

    public function test_an_employee_withdraws_their_own_pending_half_day(): void
    {
        Sanctum::actingAs($this->owner);
        $request = $this->halfDay();

        $this->postJson("/api/v1/hr/half-day-requests/{$request->id}/cancel", ['reason' => 'Appointment moved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Appointment moved');

        $this->assertSame($this->owner->id, $request->fresh()->cancelled_by);
        // A withdrawal is not a rejection.
        $this->assertNull($request->fresh()->review_note);
    }

    public function test_an_employee_withdraws_their_own_pending_early_out(): void
    {
        Sanctum::actingAs($this->owner);
        $request = $this->earlyOut();

        $this->postJson("/api/v1/hr/early-out-requests/{$request->id}/cancel", ['reason' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame($this->owner->id, $request->fresh()->cancelled_by);
    }

    public function test_an_employee_withdraws_their_own_pending_overtime(): void
    {
        Sanctum::actingAs($this->owner);
        $request = $this->overtime();

        $this->postJson("/api/v1/hr/overtime-requests/{$request->id}/cancel", ['reason' => 'Work finished early'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame($this->owner->id, $request->fresh()->cancelled_by);
    }

    public function test_an_approved_half_day_cannot_be_withdrawn_by_the_employee(): void
    {
        Sanctum::actingAs($this->owner);
        $request = $this->halfDay('approved');

        $this->postJson("/api/v1/hr/half-day-requests/{$request->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('approved', $request->fresh()->status);
    }

    /** The split that matters: approved overtime is a committed cost, so it stays HR's to undo. */
    public function test_an_employee_cannot_withdraw_their_own_approved_overtime(): void
    {
        Sanctum::actingAs($this->owner);
        $request = $this->overtime('approved');

        $this->postJson("/api/v1/hr/overtime-requests/{$request->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_hr_can_still_cancel_an_approved_overtime(): void
    {
        $request = $this->overtime('approved');

        Sanctum::actingAs($this->hrUser());

        $this->postJson("/api/v1/hr/overtime-requests/{$request->id}/cancel", ['reason' => 'Work was not performed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_another_employee_cannot_withdraw_someone_elses_request(): void
    {
        $halfDay = $this->halfDay();
        $overtime = $this->overtime();

        Sanctum::actingAs($this->otherEmployeeUser());

        $this->postJson("/api/v1/hr/half-day-requests/{$halfDay->id}/cancel", ['reason' => 'Not mine'])
            ->assertForbidden();
        $this->postJson("/api/v1/hr/overtime-requests/{$overtime->id}/cancel", ['reason' => 'Not mine'])
            ->assertForbidden();

        $this->assertSame('pending', $halfDay->fresh()->status);
        $this->assertSame('pending', $overtime->fresh()->status);
    }

    public function test_the_reason_is_required(): void
    {
        Sanctum::actingAs($this->owner);
        $request = $this->earlyOut();

        $this->postJson("/api/v1/hr/early-out-requests/{$request->id}/cancel", ['reason' => '  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('pending', $request->fresh()->status);
    }
}
