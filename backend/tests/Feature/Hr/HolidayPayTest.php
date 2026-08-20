<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** (2026-07-28 audit): a non-working holiday is NOT an absence. */
class HolidayPayTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** Monday — the day BEFORE the holiday. */
    private Carbon $mon;

    /** Tuesday — the holiday under test. */
    private Carbon $tue;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');
        // Firmly in the past so both days classify as absences, not "still could clock in".
        $this->mon = Carbon::now($tz)->subWeek()->startOfWeek();
        $this->tue = $this->mon->copy()->addDay();

        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get(); // seed the policy singleton at defaults

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);
    }

    /** Declare the Tuesday as a PH holiday of the given type. */
    private function holiday(string $type): void
    {
        $set = HolidaySet::firstOrCreate(
            ['country_code' => 'PH', 'year' => (int) $this->tue->format('Y')],
            ['source' => 'manual'],
        );

        Holiday::create([
            'holiday_set_id' => $set->id,
            'name' => 'Test Holiday',
            'date' => $this->tue->toDateString(),
            'type' => $type,
            'source' => 'manual',
        ]);
    }

    private function worked(Carbon $day): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $day->toDateString(),
            'time_in' => Carbon::parse("{$day->toDateString()} 08:00", $tz)->utc(),
            'time_out' => Carbon::parse("{$day->toDateString()} 17:00", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function unpaidDays(): float
    {
        return app(AttendanceService::class)
            ->unpaidDaysFor($this->employee, $this->mon->toDateString(), $this->tue->toDateString());
    }

    /** THE HEADLINE: worked Monday, regular holiday Tuesday → nothing unpaid. */
    public function test_unworked_regular_holiday_is_not_an_absence(): void
    {
        $this->holiday('regular');
        $this->worked($this->mon);

        $this->assertSame(0.0, $this->unpaidDays());
    }

    /** CONTROL: with no holiday declared, the same missing Tuesday still costs a day. */
    public function test_an_ordinary_missing_workday_still_costs_a_day(): void
    {
        $this->worked($this->mon);

        $this->assertSame(1.0, $this->unpaidDays());
    }

    /** A special non-working day is treated the same way — the salary covers it. */
    public function test_unworked_special_non_working_day_is_not_an_absence(): void
    {
        $this->holiday('special_non_working');
        $this->worked($this->mon);

        $this->assertSame(0.0, $this->unpaidDays());
    }

    /** `special_working` is an ordinary working day — no free pass. */
    public function test_special_working_day_behaves_as_a_normal_workday(): void
    {
        $this->holiday('special_working');
        $this->worked($this->mon);

        $this->assertSame(1.0, $this->unpaidDays());
    }

    /**
     * DOLE: holiday pay for a REGULAR holiday is forfeited when the employee was absent
     * without pay on the workday immediately before it. Monday absent → 1 day for Monday
     * PLUS the forfeited Tuesday.
     */
    public function test_regular_holiday_is_forfeited_after_an_unpaid_absence(): void
    {
        $this->holiday('regular');
        // Monday deliberately has no attendance row.

        $this->assertSame(2.0, $this->unpaidDays());
    }

    /** That forfeiture rule applies to REGULAR holidays only, not special days. */
    public function test_special_day_is_not_forfeited_after_an_absence(): void
    {
        $this->holiday('special_non_working');
        // Monday absent → only Monday is unpaid; the special day still isn't charged.

        $this->assertSame(1.0, $this->unpaidDays());
    }

    /** An unclassified holiday (type NULL — HR hasn't set it) is never guessed at. */
    public function test_unclassified_holiday_is_treated_as_an_ordinary_workday(): void
    {
        $set = HolidaySet::create(['country_code' => 'PH', 'year' => (int) $this->tue->format('Y'), 'source' => 'nager']);
        Holiday::create([
            'holiday_set_id' => $set->id, 'name' => 'Unclassified',
            'date' => $this->tue->toDateString(), 'type' => null,
            'source' => 'nager', 'needs_review' => true,
        ]);
        $this->worked($this->mon);

        $this->assertSame(1.0, $this->unpaidDays());
    }

    /** The roster labels the day `holiday`, not `absent`. */
    public function test_roster_shows_holiday_instead_of_absent(): void
    {
        $this->holiday('regular');

        $row = collect(app(AttendanceService::class)->allForDate($this->tue->toDateString()))
            ->firstWhere('employee_id', $this->employee->id);

        $this->assertSame('holiday', $row['status']);
    }

    /** And the violation engine raises no absence flag for it. */
    public function test_no_violation_is_raised_for_an_unworked_holiday(): void
    {
        $this->holiday('regular');

        app(ViolationService::class)->recompute($this->tue->format('Y-m'));

        $this->assertDatabaseMissing('hr_violations', [
            'employee_id' => $this->employee->id,
            'work_date' => $this->tue->toDateString(),
        ]);
    }
}
