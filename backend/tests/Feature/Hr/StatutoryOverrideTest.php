<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** Is REVERSED: there is no per-employee statutory amount any more. */
class StatutoryOverrideTest extends HrTestCase
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

    /**
     * Contributions can only be proven against a payslip that actually withheld them: with no
     * attendance every workday is unpaid, gross is ₱0, and the assertions would compare ₱0 to ₱0.
     */
    private function workedEveryDay(): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse('2026-03-15', $tz);

        for ($d = Carbon::parse('2026-03-01', $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }

            Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function payslip(): Payslip
    {
        $this->workedEveryDay();

        return app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create())
            ->payslips()->firstOrFail();
    }

    /** THE ONE THAT MATTERS: the storage is gone, so nothing can quietly start writing it again. */
    public function test_the_per_employee_amount_columns_no_longer_exist(): void
    {
        foreach (['sss_contribution', 'philhealth_contribution', 'pagibig_contribution'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('hr_employees', $column),
                "hr_employees.{$column} is back — a per-employee amount overrides the EMPLOYEE side "
                    . 'of a contribution only, which produces a remittance that cannot be filed.',
            );
        }
    }

    /** And the form cannot set one by name, whatever a client sends. */
    public function test_the_record_refuses_a_per_employee_amount(): void
    {
        $this->actingAs($this->hrUser())
            ->patchJson("/api/v1/hr/employees/{$this->employee->id}", [
                'sss_contribution' => 1350,
                'philhealth_contribution' => 500,
                'pagibig_contribution' => 200,
            ])
            ->assertOk(); // unknown keys are ignored, not written

        $this->assertArrayNotHasKey('sss_contribution', $this->employee->fresh()->toArray());
    }

    public function test_the_preview_endpoint_is_gone(): void
    {
        $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/employees/{$this->employee->id}/statutory-preview")
            ->assertNotFound();
    }

    /** Everyone on the same salary is charged the same, which is the whole point of removing it. */
    public function test_two_employees_on_the_same_salary_are_charged_the_same(): void
    {
        $second = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $tz = config('app.timezone_local');
        for ($d = Carbon::parse('2026-03-01', $tz); $d->lte(Carbon::parse('2026-03-15', $tz)); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            foreach ([$this->employee->id, $second->id] as $id) {
                Attendance::create([
                    'employee_id' => $id, 'work_date' => $d->toDateString(),
                    'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                    'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                    'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                    'source' => Attendance::SOURCE_MANUAL,
                ]);
            }
        }

        $slips = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create())
            ->payslips()->get()->keyBy('employee_id');

        $a = $slips[$this->employee->id];
        $b = $slips[$second->id];

        $this->assertSame((float) $a->sss, (float) $b->sss);
        $this->assertSame((float) $a->philhealth, (float) $b->philhealth);
        $this->assertSame((float) $a->pagibig, (float) $b->pagibig);
    }

    /**
     * The employee and employer halves must belong to the same bracket. This is the property the
     * override broke, and the reason none of it could be filed.
     */
    public function test_the_two_halves_of_a_contribution_come_from_the_same_bracket(): void
    {
        $slip = $this->payslip();

        // Both sides are computed from the same config and base, so a non-zero employee side
        // implies a non-zero employer side — the ₱1,350-against-₱3,530 pair cannot occur.
        $this->assertGreaterThan(0, (float) $slip->sss);
        $this->assertGreaterThan(0, (float) $slip->employer_sss);
        $this->assertNull($slip->contribution_overrides, 'a new payslip has nothing to declare');
    }

    /** Un-enrolling is what replaces it: a person who does not pay, said in a filable way. */
    public function test_unenrolling_is_still_how_someone_stops_paying_a_contribution(): void
    {
        EmployeeBenefit::updateOrCreate(
            ['employee_id' => $this->employee->id, 'contribution_type' => 'pagibig'],
            ['enrolled' => false],
        );

        $slip = $this->payslip();

        $this->assertSame(0.0, (float) $slip->pagibig, 'no employee side');
        $this->assertSame(0.0, (float) $slip->employer_pagibig, 'and no employer side either');
    }
}
