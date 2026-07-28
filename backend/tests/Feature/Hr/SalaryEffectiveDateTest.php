<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Slice 13c (2026-07-28 audit) — a period is priced at the salary in force THEN.
 *
 * `hr_salary_histories` recorded every change with an `effective_date`, but payroll read
 * `$employee->base_salary` live. So a raise silently repriced every earlier period on the next
 * re-run, and an old cycle could never be reproduced. Slice 5's lock guards `disbursed`
 * payslips; this guards the `computed` ones and makes the history meaningful.
 *
 * Documented simplification: the salary is resolved as of the CYCLE END, so a raise dated inside
 * a period applies to that whole period. No intra-period split.
 */
class SalaryEffectiveDateTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

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

        $this->workedEveryDay();
    }

    private function workedEveryDay(): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);

        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function history(float $salary, string $effective, string $type = 'adjustment'): void
    {
        SalaryHistory::create([
            'employee_id' => $this->employee->id,
            'base_salary' => $salary,
            'effective_date' => $effective,
            'reason' => 'test',
            'change_type' => $type,
        ]);
    }

    private function runPayroll(): PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    private function basePay(): float
    {
        return (float) Payslip::where('employee_id', $this->employee->id)->firstOrFail()->base_salary;
    }

    /** No history at all → fall back to the live figure, exactly as before. */
    public function test_without_history_the_live_salary_is_used(): void
    {
        $this->runPayroll();

        $this->assertSame(15000.0, $this->basePay()); // 30,000 × 12 ÷ 24
    }

    /** A raise dated AFTER the period must not leak backwards. */
    public function test_a_later_raise_does_not_affect_an_earlier_period(): void
    {
        $this->history(30000, '2020-01-01', 'hire');
        $this->history(60000, '2026-03-16'); // the next period
        $this->employee->update(['base_salary' => 60000]);

        $this->runPayroll();

        $this->assertSame(15000.0, $this->basePay(), 'priced at the old salary');
    }

    /** A raise dated before the period applies to it. */
    public function test_an_earlier_raise_applies(): void
    {
        $this->history(30000, '2020-01-01', 'hire');
        $this->history(60000, '2026-02-01');
        $this->employee->update(['base_salary' => 60000]);

        $this->runPayroll();

        $this->assertSame(30000.0, $this->basePay()); // 60,000 × 12 ÷ 24
    }

    /** THE HEADLINE: re-running an old period after a raise must not reprice it. */
    public function test_re_running_after_a_raise_does_not_reprice_the_old_period(): void
    {
        $this->history(30000, '2020-01-01', 'hire');
        $this->runPayroll();
        $original = $this->basePay();

        // A raise happens today, dated after this cycle.
        $this->history(90000, '2026-07-01');
        $this->employee->update(['base_salary' => 90000]);

        $this->runPayroll();

        $this->assertSame($original, $this->basePay());
    }

    /** Documented simplification: a mid-period raise applies to the whole period. */
    public function test_a_mid_period_raise_applies_to_the_whole_period(): void
    {
        $this->history(30000, '2020-01-01', 'hire');
        $this->history(60000, '2026-03-10'); // inside the cycle
        $this->employee->update(['base_salary' => 60000]);

        $this->runPayroll();

        $this->assertSame(30000.0, $this->basePay(), 'the whole period takes the new rate');
    }

    /** Same-day entries resolve to the later one. */
    public function test_the_latest_entry_on_a_date_wins(): void
    {
        $this->history(30000, '2020-01-01', 'hire');
        $this->history(45000, '2026-02-01');
        $this->history(50000, '2026-02-01'); // corrected the same day
        $this->employee->update(['base_salary' => 50000]);

        $this->runPayroll();

        $this->assertSame(25000.0, $this->basePay()); // 50,000 × 12 ÷ 24
    }

    /** The historical salary drives every pricing path, not just base pay. */
    public function test_the_daily_rate_follows_the_historical_salary(): void
    {
        $this->history(30000, '2020-01-01', 'hire');
        $this->history(90000, '2026-07-01'); // after the cycle
        $this->employee->update(['base_salary' => 90000]);

        $this->runPayroll();

        $slip = Payslip::where('employee_id', $this->employee->id)->firstOrFail();
        // 15,000 per period ÷ 11 scheduled workdays.
        $this->assertSame(round(15000 / (int) $slip->working_days, 2), (float) $slip->daily_rate);
    }
}
