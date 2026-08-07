<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIN-038 — pricing a payslip used to write enrolment rows.
 *
 * `ContributionService::effectiveFor()` — a read path, called per employee from inside `run()`'s
 * single transaction — called `benefitsFor()`, which `firstOrCreate`s any missing
 * `hr_employee_benefits` row. Three consequences: a read mutated enrolment data; a large run added
 * N × types INSERTs and their row locks to the critical path of a transaction that already holds the
 * payroll tables; and the first run after someone switched a contribution ON enrolled the entire
 * company as a side effect of computing pay.
 *
 * THE ASSERTION IS ON THE QUERY LOG, not on the row count, because a row count cannot tell the
 * difference between "no write happened" and "the write was idempotent and the rows already
 * existed". The audit's validation step is worded the same way: assert `run()` issues no INSERT to
 * `hr_employee_benefits`.
 */
class PayrollDoesNotWriteEnrolmentsTest extends HrTestCase
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

        $tz = config('app.timezone_local');
        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte(Carbon::parse(self::CYCLE_END, $tz)); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    /** @return array<int, string> every statement the callback issued against the enrolment table */
    private function enrolmentWritesDuring(callable $work): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $work();

        $writes = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $q) => str_contains($q, 'hr_employee_benefits')
                && (str_starts_with(strtolower(ltrim($q)), 'insert') || str_starts_with(strtolower(ltrim($q)), 'update')),
        ));

        DB::disableQueryLog();

        return $writes;
    }

    // --- the fix ----------------------------------------------------------------------------

    /** THE CASE THAT USED TO WRITE: no enrolment rows exist yet, so the old code created three. */
    public function test_a_payroll_run_writes_no_enrolment_rows_when_none_exist(): void
    {
        EmployeeBenefit::query()->delete();
        $this->assertSame(0, EmployeeBenefit::count(), 'precondition: no enrolment rows');

        $writes = $this->enrolmentWritesDuring(fn () => app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        ));

        $this->assertSame([], $writes, 'payroll wrote to hr_employee_benefits while pricing');
        $this->assertSame(0, EmployeeBenefit::count(), 'payroll created enrolment rows as a side effect');
    }

    /**
     * AND PAY IS STILL RIGHT WITH NO ROWS. This is the half that makes the read-only change safe: a
     * missing row means enrolled, so the deductions must be unchanged. Treating absence as "not
     * enrolled" would have been the tidier-looking change and would silently stop deducting SSS.
     */
    public function test_deductions_are_unchanged_when_no_enrolment_rows_exist(): void
    {
        $withRows = app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
        $before = (float) $withRows->total_deductions;

        $this->assertGreaterThan(0, $before, 'precondition: something is being deducted');

        EmployeeBenefit::query()->delete();

        $after = app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );

        $this->assertSame($before, (float) $after->total_deductions, 'removing the rows changed the pay');
    }

    /** An explicit opt-out must still be honoured — absence means enrolled, `false` means no. */
    public function test_an_explicit_opt_out_is_still_honoured(): void
    {
        app(ContributionService::class)->backfillEnrolments($this->employee);
        EmployeeBenefit::where('employee_id', $this->employee->id)
            ->where('contribution_type', 'sss')
            ->update(['enrolled' => false]);

        app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );

        $slip = \App\Modules\Hr\Models\Payslip::where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame(0.0, (float) $slip->sss, 'an un-enrolled employee was still charged SSS');
        $this->assertGreaterThan(0, (float) $slip->philhealth, 'the other contributions must be unaffected');
    }

    // --- the back-fill now happens where the change happens ---------------------------------

    public function test_creating_an_employee_creates_their_enrolment_rows(): void
    {
        // EmployeeService::create() assigns the hr_employee role, so the shared roles must exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $created = app(\App\Modules\Hr\Services\EmployeeService::class)->create([
            'first_name' => 'New', 'last_name' => 'Hire',
            'email' => 'new.hire@viteseo.ph',
            'department_id' => Department::first()->id,
            'job_title' => 'Staff', 'hire_date' => '2026-03-01',
            'base_salary' => 25000, 'pay_frequency' => 'semi_monthly',
        ]);

        $this->assertSame(
            3,
            EmployeeBenefit::where('employee_id', $created->id)->count(),
            'a new employee should have a row per active contribution',
        );
    }

    /** Switching a contribution ON back-fills everyone, instead of the next run doing it. */
    public function test_activating_a_contribution_backfills_enrolments(): void
    {
        EmployeeBenefit::query()->delete();

        $setting = ContributionSetting::where('type', 'sss')->firstOrFail();
        $setting->update(['is_active' => false]);
        EmployeeBenefit::query()->delete();

        app(ContributionService::class)->updateSetting($setting->fresh(), ['is_active' => true]);

        $this->assertGreaterThan(
            0,
            EmployeeBenefit::where('employee_id', $this->employee->id)->count(),
            'activation should have created the enrolment rows',
        );
    }
}
