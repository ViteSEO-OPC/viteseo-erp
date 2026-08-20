<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** (2026-07-28 audit) — keep the payroll run off an N+1. */
class PayrollPerformanceTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

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
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function makeEmployees(int $count): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);
        // Continue the numbering — this is called twice to grow the population.
        $offset = Employee::count();

        for ($i = $offset; $i < $offset + $count; $i++) {
            $employee = Employee::create([
                'user_id' => User::factory()->create()->id,
                'department_id' => Department::first()->id,
                'employee_no' => 'E-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'job_title' => 'Staff', 'hire_date' => '2020-01-01',
                'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            ]);

            for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end); $d->addDay()) {
                if ($d->isWeekend()) {
                    continue;
                }
                Attendance::create([
                    'employee_id' => $employee->id, 'work_date' => $d->toDateString(),
                    'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                    'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                    'status' => 'present', 'lunch_minutes' => 60, 'source' => Attendance::SOURCE_MANUAL,
                ]);
            }
        }
    }

    private function runAndCountQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * The marginal cost of one more employee. Measured by differencing two runs, so the fixed
     * setup cost cancels out and the number is stable across environments.
     */
    public function test_the_per_employee_query_cost_stays_bounded(): void
    {
        $this->makeEmployees(4);
        $small = $this->runAndCountQueries();

        $this->makeEmployees(4); // 8 total
        $large = $this->runAndCountQueries();

        $perEmployee = ($large - $small) / 4;

        $this->assertLessThan(
            15,
            $perEmployee,
            "Each extra employee now costs {$perEmployee} queries — something reintroduced an N+1.",
        );
    }

    /** THE ONE THAT MATTERS: caching must not move a single peso. */
    public function test_the_amounts_are_identical_across_repeated_runs(): void
    {
        $this->makeEmployees(5);

        $fingerprint = function (): string {
            app(PayrollService::class)->run(
                self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
            );

            return Payslip::orderBy('employee_id')->get()
                ->map(fn (Payslip $p) => implode(':', [
                    $p->employee_id, $p->gross, $p->total_deductions,
                    $p->net_pay, $p->daily_rate, $p->unpaid_days,
                ]))
                ->implode('|');
        };

        $this->assertSame($fingerprint(), $fingerprint());
    }

    /** A memo must never outlive the data it caches. */
    public function test_an_attendance_correction_is_seen_by_the_next_run(): void
    {
        $this->makeEmployees(1);
        $employee = Employee::firstOrFail();

        app(PayrollService::class)->run(self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create());
        $before = (float) Payslip::firstOrFail()->base_salary;

        // Wipe a worked day — the same request instance already cached those rows.
        app(\App\Modules\Hr\Services\AttendanceService::class)
            ->adjust($employee, '2026-03-02', ['time_in' => null]);

        app(PayrollService::class)->run(self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create());

        $this->assertLessThan($before, (float) Payslip::firstOrFail()->fresh()->base_salary);
    }
}
