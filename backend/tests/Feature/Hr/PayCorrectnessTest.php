<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Slice 13a (2026-07-28 audit) — the pay-affecting half of the correctness cleanups.
 *
 * Each test names the item it guards. These are individually small but all of them either move
 * money or stop money moving when it shouldn't.
 */
class PayCorrectnessTest extends HrTestCase
{
    use RefreshDatabase;

    /** A Sunday — a rest day on the Mon–Fri schedule. */
    private const RESTDAY = '2026-03-01';

    /** The Monday after it. */
    private const WORKDAY = '2026-03-02';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00', 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);
    }

    private function worked(string $date, string $in = '08:00', string $out = '17:00'): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$in}", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} {$out}", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function holiday(string $date, string $type): void
    {
        $set = HolidaySet::firstOrCreate(
            ['country_code' => 'PH', 'year' => (int) Carbon::parse($date)->format('Y')],
            ['source' => 'manual'],
        );
        Holiday::create([
            'holiday_set_id' => $set->id, 'name' => 'Test', 'date' => $date,
            'type' => $type, 'source' => 'manual',
        ]);
    }

    private function unpaidDays(string $from, string $to): float
    {
        return app(AttendanceService::class)->unpaidDaysFor($this->employee->fresh(), $from, $to);
    }

    // --- item 1: the payslip must reconcile ------------------------------------------

    public function test_deductions_are_capped_at_gross_and_the_shortfall_is_recorded(): void
    {
        // Almost every day unpaid → tiny gross, but statutory deductions are on full salary.
        $slip = app(PayrollService::class)->payslipFor(
            employee: $this->employee, reimb: 0.0, unpaidDays: 10.8,
            periodsPerYear: 24, workingDaysInPeriod: 11,
            dist: ['index' => 0, 'count' => 2, 'deduction_timing' => 'every', 'tax_timing' => 'every'],
        );

        $this->assertGreaterThan(0, $slip['deduction_carryover'], 'the shortfall must be recorded');
        $this->assertSame($slip['gross'], $slip['total_deductions'], 'never take more than gross');
        $this->assertSame(0.0, $slip['net_pay']);
        // The headline figures now reconcile.
        $this->assertSame(
            round($slip['gross'] - $slip['total_deductions'], 2),
            $slip['net_pay'],
        );
    }

    // --- item 4: a holiday on a rest day ---------------------------------------------

    public function test_a_holiday_worked_on_a_rest_day_pays_the_full_rate(): void
    {
        $this->holiday(self::RESTDAY, 'regular');   // a Sunday
        $this->holiday(self::WORKDAY, 'regular');   // the Monday
        $this->worked(self::RESTDAY);
        $this->worked(self::WORKDAY);

        $run = app(PayrollService::class)->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $slip = $run->payslips()->firstOrFail();

        // Workday holiday pays the +100% add-on; the rest-day one pays the whole 200%, because
        // the salary never covered that day. Together: 1.0 + 2.0 = 3× the daily rate.
        $this->assertSame(round((float) $slip->daily_rate * 3, 2), (float) $slip->holiday_premium);
    }

    // --- item 5: a day can never cost more than a day --------------------------------

    public function test_half_days_cannot_exceed_one_full_day(): void
    {
        foreach (['AM', 'PM', 'AM'] as $period) {
            HalfDayRequest::create([
                'employee_id' => $this->employee->id, 'work_date' => self::WORKDAY,
                'period' => $period, 'reason' => 'x', 'status' => 'approved',
            ]);
        }

        $this->assertSame(1.0, $this->unpaidDays(self::WORKDAY, self::WORKDAY));
    }

    // --- item 8: half-day + early-out / lateness -------------------------------------

    public function test_a_late_arrival_on_a_half_day_still_counts_toward_the_penalty(): void
    {
        AttendancePolicy::query()->update(['late_penalty_enabled' => true, 'lates_per_deduction' => 1]);

        HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => self::WORKDAY,
            'period' => 'AM', 'reason' => 'x', 'status' => 'approved',
        ]);
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::WORKDAY,
            'time_in' => Carbon::parse(self::WORKDAY . ' 14:00', $tz)->utc(),
            'time_out' => Carbon::parse(self::WORKDAY . ' 17:00', $tz)->utc(),
            'status' => 'late', 'lunch_minutes' => 0, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);

        // 0.5 for the half-day + 1.0 for the per-N-lates penalty that used to be skipped.
        $this->assertSame(1.5, $this->unpaidDays(self::WORKDAY, self::WORKDAY));
    }

    // --- item 10: a retired leave type must not turn LWOP into paid leave ------------

    public function test_unpaid_leave_stays_unpaid_after_its_type_is_deleted(): void
    {
        $type = LeaveType::create(['name' => 'LWOP', 'code' => 'LWOP', 'is_paid' => false, 'default_days' => 0]);
        LeaveRequest::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'start_date' => self::WORKDAY, 'end_date' => self::WORKDAY,
            'days' => 1, 'reason' => 'x', 'status' => 'approved',
        ]);

        $this->assertSame(1.0, $this->unpaidDays(self::WORKDAY, self::WORKDAY));

        $type->delete(); // retired — used to make the day PAID

        $this->assertSame(1.0, $this->unpaidDays(self::WORKDAY, self::WORKDAY));
    }

    // --- item 11: no scheduled workdays must fail loudly -----------------------------

    public function test_an_employee_with_no_workdays_fails_instead_of_being_paid_in_full(): void
    {
        $this->employee->update(['custom_workdays' => []]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no scheduled workdays');

        app(PayrollService::class)->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
    }

    public function test_the_endpoint_rejects_an_empty_workdays_list(): void
    {
        $this->actingAsHr()
            ->putJson("/api/v1/hr/employees/{$this->employee->id}/schedule", ['custom_workdays' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_workdays');
    }
}
