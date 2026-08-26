<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Models\WorkSuspension;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\ViolationService;
use App\Modules\Hr\Services\WorkSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** HR-declared non-working days — a typhoon, a shutdown. */
class WorkSuspensionTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** A Wednesday, and in the PAST relative to `NOW` below — so absences would normally bite. */
    private const SUSPENDED = '2026-07-08';

    private const NOW = '2026-07-20 09:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Day', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 15, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        // The violation engine treats the earliest human-entered record as "tracking started",
        // so without one every past day is untracked and nothing would flag either way — which
        // would make the absence tests below pass for the wrong reason.
        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-07-01',
            'time_in' => Carbon::parse('2026-07-01 09:00', config('app.timezone_local'))->utc(),
            'time_out' => Carbon::parse('2026-07-01 18:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): WorkSuspensionService
    {
        return app(WorkSuspensionService::class);
    }

    private function declare(bool $isPaid, string $date = self::SUSPENDED): WorkSuspension
    {
        return $this->service()->declare([
            'date' => $date,
            'name' => 'Typhoon Egay',
            'is_paid' => $isPaid,
        ], $this->hrUser())['suspension'];
    }

    private function unpaidDaysOn(string $date): float
    {
        return app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee, $date, $date)
            ->unpaidDays;
    }

    private function rosterStatusOn(string $date): string
    {
        return collect(app(AttendanceService::class)->allForDate($date)->items())
            ->firstWhere('employee_id', $this->employee->id)['status'];
    }

    // --- payroll -------------------------------------------------------------------------

    public function test_an_unsuspended_missed_workday_costs_a_day(): void
    {
        // The control. Without it, "paid suspension costs nothing" proves nothing.
        $this->assertSame(1.0, $this->unpaidDaysOn(self::SUSPENDED));
    }

    public function test_a_paid_suspension_costs_nobody_a_day(): void
    {
        $this->declare(isPaid: true);

        $this->assertSame(0.0, $this->unpaidDaysOn(self::SUSPENDED));
    }

    public function test_an_unpaid_suspension_costs_one_day(): void
    {
        $this->declare(isPaid: false);

        $this->assertSame(1.0, $this->unpaidDaysOn(self::SUSPENDED));
    }

    public function test_flipping_paid_to_unpaid_reprices_the_day_with_no_rows_to_migrate(): void
    {
        $suspension = $this->declare(isPaid: true);
        $this->assertSame(0.0, $this->unpaidDaysOn(self::SUSPENDED));

        $this->service()->update($suspension, ['is_paid' => false], $this->hrUser());

        $this->assertSame(1.0, $this->unpaidDaysOn(self::SUSPENDED));
        $this->assertSame(
            0,
            Attendance::where('work_date', self::SUSPENDED)->count(),
            'The status is derived — materialising rows is what makes a flip go stale.',
        );
    }

    public function test_someone_who_worked_through_an_unpaid_suspension_is_still_paid(): void
    {
        $this->declare(isPaid: false);

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => self::SUSPENDED,
            'time_in' => Carbon::parse(self::SUSPENDED . ' 09:00', config('app.timezone_local'))->utc(),
            'time_out' => Carbon::parse(self::SUSPENDED . ' 18:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
            'source' => Attendance::SOURCE_MANUAL,
        ]);

        $this->assertSame(0.0, $this->unpaidDaysOn(self::SUSPENDED));
    }

    public function test_an_unpaid_suspension_does_not_forfeit_the_next_regular_holiday(): void
    {
        // The DOLE forfeiture rule is about the EMPLOYEE being absent without pay. A day the
        // company cancelled is not that, so charging the day AND the holiday would punish them
        // twice for someone else's decision.
        $this->declare(isPaid: false, date: '2026-07-08');

        $detail = app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee, '2026-07-08', '2026-07-09');

        // Only the suspended day itself is unpaid; the day after is untouched by forfeiture.
        $this->assertSame(2.0, $detail->unpaidDays, 'The 9th is a plain missed workday: 1 + 1.');
    }

    // --- the roster ----------------------------------------------------------------------

    public function test_the_roster_shows_the_paid_or_unpaid_status(): void
    {
        $this->declare(isPaid: true);
        $this->assertSame('suspended_paid', $this->rosterStatusOn(self::SUSPENDED));

        $this->service()->update(WorkSuspension::firstOrFail(), ['is_paid' => false], $this->hrUser());
        $this->assertSame('suspended_unpaid', $this->rosterStatusOn(self::SUSPENDED));
    }

    public function test_the_roster_shows_whoever_actually_worked_it(): void
    {
        $this->declare(isPaid: true);

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => self::SUSPENDED,
            'time_in' => Carbon::parse(self::SUSPENDED . ' 09:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
            'source' => Attendance::SOURCE_MANUAL,
        ]);

        $this->assertSame('present', $this->rosterStatusOn(self::SUSPENDED));
    }

    public function test_the_clock_panel_names_the_suspension(): void
    {
        $this->declare(isPaid: true, date: '2026-07-20'); // NOW is a Monday

        $window = app(AttendanceService::class)->clockInWindowFor($this->employee);

        $this->assertSame('Typhoon Egay', $window['suspension']['name']);
        $this->assertTrue($window['suspension']['is_paid']);
    }

    // --- violations ----------------------------------------------------------------------

    public function test_a_suspended_day_raises_no_absence_flag_paid_or_unpaid(): void
    {
        $this->declare(isPaid: false);

        app(ViolationService::class)->recompute('2026-07');

        $this->assertSame(
            0,
            Violation::where('employee_id', $this->employee->id)
                ->where('work_date', self::SUSPENDED)
                ->count(),
            'Not coming in on a day the company cancelled is not a disciplinary matter.',
        );
    }

    public function test_without_a_suspension_the_same_day_does_flag(): void
    {
        app(ViolationService::class)->recompute('2026-07');

        $this->assertGreaterThan(
            0,
            Violation::where('employee_id', $this->employee->id)
                ->where('work_date', self::SUSPENDED)
                ->count(),
            'The control: this day must flag, or the test above proves nothing.',
        );
    }

    // --- guards --------------------------------------------------------------------------

    public function test_a_locked_payroll_run_refuses_the_declaration(): void
    {
        PayrollRun::create([
            'cycle_start' => '2026-07-01', 'cycle_end' => '2026-07-15',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'run_at' => now(), 'locked_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->declare(isPaid: true);
    }

    public function test_an_already_run_but_unlocked_period_warns_instead_of_refusing(): void
    {
        PayrollRun::create([
            'cycle_start' => '2026-07-01', 'cycle_end' => '2026-07-15',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'run_at' => now(),
        ]);

        $result = $this->service()->declare([
            'date' => self::SUSPENDED, 'name' => 'Typhoon Egay', 'is_paid' => true,
        ], $this->hrUser());

        $this->assertNotNull($result['suspension']->id);
        $this->assertStringContainsString('Re-run it', (string) $result['warning']);
    }

    public function test_redeclaring_a_withdrawn_date_revives_it_rather_than_colliding(): void
    {
        // The unique index on `date` ignores deleted_at, so a naive insert would 1062 here.
        $first = $this->declare(isPaid: true);
        $this->service()->withdraw($first, $this->hrUser());

        $again = $this->declare(isPaid: false);

        $this->assertSame($first->id, $again->id);
        $this->assertFalse($again->is_paid);
        $this->assertSame(1, WorkSuspension::where('date', self::SUSPENDED)->count());
    }

    public function test_withdrawing_returns_the_day_to_an_ordinary_workday(): void
    {
        $suspension = $this->declare(isPaid: true);
        $this->assertSame(0.0, $this->unpaidDaysOn(self::SUSPENDED));

        $this->service()->withdraw($suspension, $this->hrUser());

        $this->assertSame(1.0, $this->unpaidDaysOn(self::SUSPENDED));
    }

    public function test_hr_can_override_one_person_to_unpaid_on_a_paid_suspension(): void
    {
        // The skeleton-crew inverse: a company-wide paid day, one person HR docks anyway.
        $this->declare(isPaid: true);

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => self::SUSPENDED,
            'status' => 'suspended_unpaid',
            'status_override' => true,
            'source' => Attendance::SOURCE_MANUAL,
        ]);

        $this->assertSame(1.0, $this->unpaidDaysOn(self::SUSPENDED));
    }
}
