<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmploymentHistory;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\SalaryHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Hand-editing the two timelines on the employee record. */
class HistoryEditingTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

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
    }

    private function openRole(string $title = 'Staff', string $start = '2020-01-01'): EmploymentHistory
    {
        return EmploymentHistory::create([
            'employee_id' => $this->employee->id, 'title' => $title,
            'start_date' => $start, 'end_date' => null, 'status' => 'current',
        ]);
    }

    private function salaryRow(float $amount, string $effective, string $type = 'adjustment'): SalaryHistory
    {
        return SalaryHistory::create([
            'employee_id' => $this->employee->id, 'base_salary' => $amount,
            'effective_date' => $effective, 'reason' => 'seed', 'change_type' => $type,
        ]);
    }

    /** A completed regular run with a payslip for this employee — what makes a date "settled". */
    private function completedRun(string $start, string $end): PayrollRun
    {
        $run = PayrollRun::create([
            'cycle_start' => $start, 'cycle_end' => $end, 'frequency' => 'semi_monthly',
            'type' => 'regular', 'status' => 'completed', 'run_at' => now(),
        ]);

        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'base_salary' => 30000, 'gross' => 15000, 'total_deductions' => 0,
            'net_pay' => 15000, 'status' => 'disbursed',
        ]);

        return $run;
    }

    // --- employment timeline: free, one structural rule ------------------------------------------

    public function test_hr_can_add_a_role_that_nobody_recorded_at_the_time(): void
    {
        $this->openRole();

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/employment-histories", [
                'title' => 'Intern',
                'start_date' => '2019-01-01',
                'end_date' => '2019-12-31',
                'status' => 'role_change',
                'reason' => 'Backfilled from the paper file',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Intern')
            ->assertJsonPath('data.end_date', '2019-12-31');
    }

    public function test_hr_can_correct_a_typo_in_a_role_title(): void
    {
        $role = $this->openRole('Staf');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/employment-histories/{$role->id}", [
                'title' => 'Staff',
                'end_date' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Staff');

        $this->assertNull($role->refresh()->end_date, 'still the current role');
    }

    public function test_hr_can_delete_a_duplicate_role(): void
    {
        $this->openRole();
        $dupe = EmploymentHistory::create([
            'employee_id' => $this->employee->id, 'title' => 'Staff',
            'start_date' => '2020-01-01', 'end_date' => '2020-06-30', 'status' => 'role_change',
        ]);

        $this->actingAs($this->hrUser())
            ->deleteJson("/api/v1/hr/employment-histories/{$dupe->id}")
            ->assertNoContent();

        $this->assertSame(1, EmploymentHistory::count());
    }

    /** Two open roles and the next promotion closes an arbitrary one of them. */
    public function test_a_second_open_role_is_refused(): void
    {
        $this->openRole();

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/employment-histories", [
                'title' => 'Also Staff',
                'start_date' => '2024-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    /** Same rule from the other direction: reopening a closed role while one is already open. */
    public function test_reopening_a_role_while_another_is_open_is_refused(): void
    {
        $this->openRole();
        $closed = EmploymentHistory::create([
            'employee_id' => $this->employee->id, 'title' => 'Junior',
            'start_date' => '2018-01-01', 'end_date' => '2019-12-31', 'status' => 'promoted',
        ]);

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/employment-histories/{$closed->id}", ['end_date' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_a_role_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/employment-histories", [
                'title' => 'Backwards',
                'start_date' => '2024-01-01',
                'end_date' => '2023-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    /** A row with no end date renders as "Present", so it cannot carry an ended-role badge. */
    public function test_an_open_role_is_always_marked_current(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/employment-histories", [
                'title' => 'Staff', 'start_date' => '2020-01-01', 'status' => 'promoted',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'current');
    }

    /** THE REGRESSION THAT MATTERS: hand-editing must not have replaced the automation. */
    public function test_changing_the_job_title_still_moves_the_timeline_by_itself(): void
    {
        $role = $this->openRole('Staff');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/employees/{$this->employee->id}", [
                'job_title' => 'Senior Staff',
                'role_change_type' => 'promotion',
                'role_change_reason' => 'Q2 review',
            ])
            ->assertOk();

        $this->assertSame('promoted', $role->refresh()->status, 'the old role was closed');
        $this->assertSame(
            'Senior Staff',
            EmploymentHistory::whereNull('end_date')->firstOrFail()->title,
            'and a new open one was appended',
        );
    }

    public function test_an_employee_cannot_edit_their_own_timeline(): void
    {
        $role = $this->openRole();

        $this->actingAs($this->employee->user)
            ->patchJson("/api/v1/hr/employment-histories/{$role->id}", [
                'title' => 'Chief Executive', 'end_date' => null,
            ])
            ->assertForbidden();
    }

    // --- compensation timeline: guarded, because these rows are money ----------------------------

    public function test_an_unsettled_entry_is_freely_editable(): void
    {
        $this->salaryRow(30000, '2020-01-01');
        $future = $this->salaryRow(40000, '2026-12-01', 'merit');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/salary-histories/{$future->id}", ['base_salary' => 41000])
            ->assertOk()
            ->assertJsonPath('data.base_salary', 41000);
    }

    public function test_an_unsettled_entry_is_freely_deletable(): void
    {
        $this->salaryRow(30000, '2020-01-01');
        $future = $this->salaryRow(40000, '2026-12-01', 'merit');

        $this->actingAs($this->hrUser())
            ->deleteJson("/api/v1/hr/salary-histories/{$future->id}")
            ->assertNoContent();

        $this->assertSame(1, SalaryHistory::count());
    }

    /** The correction is refused once, naming what it would reprice. */
    public function test_correcting_a_settled_entry_is_refused_until_acknowledged(): void
    {
        $row = $this->salaryRow(30000, '2026-06-01');
        $this->completedRun('2026-06-01', '2026-06-15');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/salary-histories/{$row->id}", ['base_salary' => 32000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_date');

        $this->assertSame(30000.0, (float) $row->refresh()->base_salary, 'nothing moved');
    }

    public function test_acknowledging_lets_the_correction_through_and_raises_the_difference(): void
    {
        $row = $this->salaryRow(30000, '2026-06-01');
        $this->completedRun('2026-06-01', '2026-06-15');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/salary-histories/{$row->id}", [
                'base_salary' => 32000,
                'acknowledge_repricing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.base_salary', 32000);

        $this->assertDatabaseHas('hr_retro_flags', [
            'flaggable_type' => SalaryHistory::class,
            'flaggable_id' => $row->id,
            'employee_id' => $this->employee->id,
            'resolved_at' => null,
        ]);
    }

    /**
     * THE ONE WITH NO OVERRIDE. An edit leaves a corrected basis for the payslip that was issued;
     * a delete leaves none, and payroll falls back to whatever the salary column says today.
     */
    public function test_a_settled_entry_cannot_be_deleted_even_with_an_acknowledgement(): void
    {
        $this->salaryRow(28000, '2020-01-01');
        $row = $this->salaryRow(30000, '2026-06-01');
        $this->completedRun('2026-06-01', '2026-06-15');

        $this->actingAs($this->hrUser())
            ->deleteJson("/api/v1/hr/salary-histories/{$row->id}", ['acknowledge_repricing' => true])
            ->assertStatus(422);

        $this->assertNotNull(SalaryHistory::find($row->id), 'still there');
    }

    /** No rows at all and `salariesAsOf()` prices every period ever run at today's figure. */
    public function test_the_last_remaining_entry_cannot_be_deleted(): void
    {
        $only = $this->salaryRow(30000, '2020-01-01');

        $this->actingAs($this->hrUser())
            ->deleteJson("/api/v1/hr/salary-histories/{$only->id}")
            ->assertStatus(422);

        $this->assertSame(1, SalaryHistory::count());
    }

    public function test_adding_a_backdated_entry_is_refused_until_acknowledged(): void
    {
        $this->salaryRow(30000, '2020-01-01');
        $this->completedRun('2026-06-01', '2026-06-15');

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/salary-histories", [
                'base_salary' => 33000, 'effective_date' => '2026-06-05', 'change_type' => 'merit',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_date');
    }

    /**
     * The record page reads `hr_employees.base_salary` while payroll prices from the timeline.
     * Editing the newest entry without re-syncing leaves the two showing different numbers on the
     * same screen, and the wrong one looks authoritative.
     */
    public function test_editing_the_entry_in_force_moves_the_salary_on_the_record(): void
    {
        $this->salaryRow(30000, '2020-01-01');
        $current = $this->salaryRow(35000, '2026-01-01', 'merit');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/salary-histories/{$current->id}", ['base_salary' => 36000])
            ->assertOk();

        $this->assertSame(36000.0, (float) $this->employee->fresh()->base_salary);
    }

    /** And deleting it hands the record back to the entry underneath. */
    public function test_deleting_the_entry_in_force_falls_back_to_the_previous_one(): void
    {
        $this->salaryRow(30000, '2020-01-01');
        $current = $this->salaryRow(35000, '2026-01-01', 'merit');

        $this->actingAs($this->hrUser())
            ->deleteJson("/api/v1/hr/salary-histories/{$current->id}")
            ->assertNoContent();

        $this->assertSame(30000.0, (float) $this->employee->fresh()->base_salary);
    }

    /** A raise dated next month is recorded, but it is not what the person earns yet. */
    public function test_a_future_entry_does_not_become_the_current_salary(): void
    {
        $this->salaryRow(30000, '2020-01-01');

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/salary-histories", [
                'base_salary' => 50000, 'effective_date' => '2026-12-01', 'change_type' => 'merit',
            ])
            ->assertCreated();

        $this->assertSame(30000.0, (float) $this->employee->fresh()->base_salary);
    }

    /** Same regression check as the timeline above: the automatic append still happens. */
    public function test_changing_the_salary_still_appends_an_entry_by_itself(): void
    {
        $this->salaryRow(30000, '2020-01-01');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/employees/{$this->employee->id}", [
                'base_salary' => 34000,
                'salary_change_reason' => 'Merit increase',
                'salary_change_type' => 'merit',
            ])
            ->assertOk();

        $this->assertSame(2, SalaryHistory::count());
        $this->assertSame(34000.0, (float) SalaryHistory::latest('id')->first()->base_salary);
    }

    public function test_an_employee_cannot_edit_their_own_compensation_history(): void
    {
        $row = $this->salaryRow(30000, '2020-01-01');

        $this->actingAs($this->employee->user)
            ->patchJson("/api/v1/hr/salary-histories/{$row->id}", ['base_salary' => 999999])
            ->assertForbidden();
    }

    public function test_an_unknown_change_type_is_refused(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/employees/{$this->employee->id}/salary-histories", [
                'base_salary' => 40000, 'effective_date' => '2026-12-01', 'change_type' => 'bonus',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('change_type');
    }

    /** Guard against the retro flag piling up one row per re-save of the same correction. */
    public function test_correcting_the_same_entry_twice_leaves_one_outstanding_difference(): void
    {
        $row = $this->salaryRow(30000, '2026-06-01');
        $this->completedRun('2026-06-01', '2026-06-15');

        foreach ([32000, 33000] as $amount) {
            $this->actingAs($this->hrUser())
                ->patchJson("/api/v1/hr/salary-histories/{$row->id}", [
                    'base_salary' => $amount, 'acknowledge_repricing' => true,
                ])
                ->assertOk();
        }

        $this->assertSame(1, RetroFlag::where('flaggable_id', $row->id)
            ->where('flaggable_type', SalaryHistory::class)->count());
    }
}
