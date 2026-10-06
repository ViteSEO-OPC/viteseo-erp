<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Employment TYPE (regular, fixed_term, seasonal, …) and employment STATUS (active, floating,
 * suspended, separated) are two separate questions. HR sets the first three statuses directly;
 * "separated" is only ever derived from the separation flow, so it cannot disagree with it.
 */
class EmployeeWorkStatusTest extends HrTestCase
{
    use RefreshDatabase;

    private Department $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function makeEmployee(string $name, array $attrs = []): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'department_id' => $this->ops->id,
            'employee_no' => sprintf('WS-%03d', $n),
            'job_title' => 'Staff',
            'hire_date' => '2021-01-01',
            'employment_status' => $attrs['employment_status'] ?? 'regular',
            'work_status' => $attrs['work_status'] ?? 'active',
            'base_salary' => 25000,
            'pay_frequency' => 'semi_monthly',
            'separation_date' => $attrs['separation_date'] ?? null,
        ]);
    }

    public function test_a_new_record_is_active_by_default(): void
    {
        $employee = $this->makeEmployee('Ana');

        $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.work_status', 'active');
    }

    public function test_hr_can_set_floating_and_suspended(): void
    {
        $employee = $this->makeEmployee('Ben');
        $hr = $this->hrUser();

        foreach (['floating', 'suspended', 'active'] as $status) {
            $this->actingAs($hr)
                ->patchJson("/api/v1/hr/employees/{$employee->id}", ['work_status' => $status])
                ->assertOk()
                ->assertJsonPath('data.work_status', $status);
        }
    }

    public function test_separated_cannot_be_set_directly(): void
    {
        $employee = $this->makeEmployee('Cara');

        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/employees/{$employee->id}", ['work_status' => 'separated'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_status');
    }

    public function test_a_separated_employee_reads_as_separated_whatever_was_stored(): void
    {
        $employee = $this->makeEmployee('Dino', ['work_status' => 'suspended', 'separation_date' => '2026-12-31']);

        $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.work_status', 'separated');
    }

    public function test_the_new_employment_types_are_accepted_and_the_old_ones_are_not(): void
    {
        $employee = $this->makeEmployee('Eli');
        $hr = $this->hrUser();

        foreach (['fixed_term', 'seasonal', 'project_based'] as $type) {
            $this->actingAs($hr)
                ->patchJson("/api/v1/hr/employees/{$employee->id}", ['employment_status' => $type])
                ->assertOk()
                ->assertJsonPath('data.employment_status', $type);
        }

        foreach (['contract', 'part_time'] as $retired) {
            $this->actingAs($hr)
                ->patchJson("/api/v1/hr/employees/{$employee->id}", ['employment_status' => $retired])
                ->assertStatus(422)
                ->assertJsonValidationErrors('employment_status');
        }
    }

    public function test_the_directory_filters_by_work_status(): void
    {
        $this->makeEmployee('Fe', ['work_status' => 'floating']);
        $this->makeEmployee('Gil');

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees?work_status=floating')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fe')
            ->assertJsonPath('data.0.work_status', 'floating');
    }
}
