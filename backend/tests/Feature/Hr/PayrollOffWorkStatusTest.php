<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Floating status and suspension: no work, no pay. While off work an employee is paid strictly
 * basic pay for the days attendance shows they worked, management included. Allowances are a
 * fixed rate and stay whole.
 */
class PayrollOffWorkStatusTest extends HrTestCase
{
    use RefreshDatabase;

    // 2026-03-02 (Mon) .. 2026-03-13 (Fri): 10 scheduled workdays.
    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    private Department $dept;

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
        $this->dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);

        BenefitProgram::create([
            'name' => 'Meal Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 4000, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);
    }

    private function employee(string $no, string $workStatus = 'active', bool $management = false): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $this->dept->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => $management, 'employment_status' => 'regular',
            'work_status' => $workStatus,
        ]);
    }

    /** Clock this employee in on the first `$days` workdays of the cycle. */
    private function worked(Employee $employee, int $days): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);
        $logged = 0;

        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end) && $logged < $days; $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
            $logged++;
        }
    }

    private function runPayroll(): void
    {
        app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    private function slip(Employee $employee): Payslip
    {
        return Payslip::where('employee_id', $employee->id)->firstOrFail();
    }

    public function test_a_floating_employee_who_did_not_work_is_paid_nothing(): void
    {
        $floating = $this->employee('E-FL', 'floating');

        $this->runPayroll();

        $slip = $this->slip($floating);
        $this->assertSame(0.0, (float) $slip->base_salary, 'no work, no basic pay');
        $this->assertSame(2000.0, (float) $slip->allowances, 'the allowance is a fixed rate');
    }

    /** Suspended mid-cycle: the days worked before it are still owed — and only those. */
    public function test_a_suspended_employee_is_paid_for_the_days_they_worked(): void
    {
        $suspended = $this->employee('E-SU', 'suspended');
        $this->worked($suspended, 4);

        $this->runPayroll();

        $slip = $this->slip($suspended);
        $this->assertEqualsWithDelta(15000 * 4 / 10, (float) $slip->base_salary, 0.05);
        $this->assertSame(2000.0, (float) $slip->allowances, 'the allowance is a fixed rate');
    }

    /** Management's fixed salary does not survive floating status. */
    public function test_a_floating_manager_is_paid_by_attendance_not_a_fixed_salary(): void
    {
        $manager = $this->employee('E-MG', 'floating', management: true);
        $this->worked($manager, 5);

        $this->runPayroll();

        $slip = $this->slip($manager);
        $this->assertEqualsWithDelta(15000 * 5 / 10, (float) $slip->base_salary, 0.05);
        $this->assertSame(2000.0, (float) $slip->allowances, 'the allowance is a fixed rate');
        $this->assertTrue($manager->fresh()->is_management, 'the record itself is never changed');
    }

    /** The behaviour everyone else relies on is unchanged. */
    public function test_active_staff_are_priced_exactly_as_before(): void
    {
        $absentee = $this->employee('E-AC');
        $this->worked($absentee, 4);
        $manager = $this->employee('E-AM', management: true);

        $this->runPayroll();

        $absent = $this->slip($absentee);
        $this->assertEqualsWithDelta(15000 * 4 / 10, (float) $absent->base_salary, 0.05);
        $this->assertSame(2000.0, (float) $absent->allowances, 'an absence never reduces an allowance');

        $fixed = $this->slip($manager);
        $this->assertSame(15000.0, (float) $fixed->base_salary, 'management keeps the fixed salary');
        $this->assertSame(2000.0, (float) $fixed->allowances);
    }
}
