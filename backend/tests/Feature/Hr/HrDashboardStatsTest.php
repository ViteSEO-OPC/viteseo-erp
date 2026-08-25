<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * THE HR DASHBOARD'S SUMMARY CARDS. Every one of them is read at a glance and taken on trust,
 * which is exactly why the Next Payroll card announcing September 1st on the 21st of August went
 * unquestioned for as long as it did.
 */
class HrDashboardStatsTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-21 10:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        PayrollSetting::current()->update(['frequency' => 'semi_monthly', 'pay_delay_days' => 0]);

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function stats(): array
    {
        return app(DashboardService::class)->hrStats();
    }

    /**
     * THE HEADLINE: the card follows the pay SCHEDULE, not whatever has been run. On the 21st of a
     * semi-monthly August the next payday is the 31st — and it stays the 31st however early the
     * 16–31 cutoff was processed.
     */
    public function test_next_payroll_is_the_next_pay_date_not_the_day_after_the_last_run(): void
    {
        // The exact shape that produced the bug: the 16–31 cutoff already completed, so the old
        // `cycle_end + 1 day` answered with the first of September.
        PayrollRun::create([
            'cycle_start' => '2026-08-16', 'cycle_end' => '2026-08-31',
            'frequency' => 'semi_monthly', 'status' => 'completed', 'type' => 'regular',
            'run_at' => now(), 'total_gross' => 0, 'total_net' => 0, 'total_deductions' => 0,
        ]);

        $this->assertSame('2026-08-31', $this->stats()['next_payroll_date']);
    }

    /** With nothing ever run, the answer is the same — it never came from the runs. */
    public function test_next_payroll_needs_no_completed_run_at_all(): void
    {
        $this->assertSame('2026-08-31', $this->stats()['next_payroll_date']);
    }

    /** A pay delay moves the payday off the cycle end, and the card has to move with it. */
    public function test_next_payroll_respects_the_configured_pay_delay(): void
    {
        PayrollSetting::current()->update(['pay_delay_days' => 3]);

        $this->assertSame('2026-09-03', $this->stats()['next_payroll_date']);
    }

    /**
     * PAY GROUPS: a weekly employee is paid before the semi-monthly default group, so the
     * company's next payday is theirs. Reading only the company default would have said the 31st.
     */
    public function test_next_payroll_is_the_earliest_across_the_pay_groups_in_use(): void
    {
        $this->employee->update(['pay_frequency' => 'monthly']);

        Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'weekly', 'is_management' => false,
        ]);

        $next = $this->stats()['next_payroll_date'];

        $this->assertLessThan('2026-08-31', $next);
        $this->assertGreaterThanOrEqual('2026-08-21', $next);
    }

    /** Two approved leaves covering today are still ONE person on leave. */
    public function test_on_leave_today_counts_people_not_requests(): void
    {
        $type = LeaveType::create([
            'name' => 'Vacation', 'code' => 'VL', 'default_days' => 10, 'is_paid' => true,
        ]);

        foreach ([['2026-08-19', '2026-08-21'], ['2026-08-21', '2026-08-25']] as [$from, $to]) {
            LeaveRequest::create([
                'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
                'start_date' => $from, 'end_date' => $to, 'days' => 3,
                'reason' => 'test', 'status' => 'approved',
            ]);
        }

        $this->assertSame(1, $this->stats()['on_leave_today']);
    }

    /** Every point on the trend is labelled the same way, including the days nobody clocked in. */
    public function test_the_attendance_trend_labels_quiet_days_like_every_other_day(): void
    {
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-20',
            'time_in' => Carbon::parse('2026-08-20 08:00', config('app.timezone_local'))->utc(),
            'time_out' => Carbon::parse('2026-08-20 17:00', config('app.timezone_local'))->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);

        $trend = $this->stats()['attendance_trend'];

        $this->assertCount(30, $trend);
        foreach ($trend as $point) {
            $this->assertStringNotContainsString('-', $point['date'], 'a raw ISO date reached the chart axis');
        }

        $worked = collect($trend)->firstWhere('date', 'Aug 20');
        $this->assertSame(100, $worked['on_time']);
    }
}
