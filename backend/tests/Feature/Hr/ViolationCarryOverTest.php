<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 *  (2026-07-27): the late tally can carry across months (`monthly_late_reset` OFF), and a
 * resolved violation stops counting toward escalation. Default ladder: green@1, yellow@3, orange@4,
 * red@6. Three unresolved lates last month + two this month ⇒ this month's colour depends on the
 * toggle and on what HR has resolved. Flags only — no pay effect.
 */
class ViolationCarryOverTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Carbon $prevStart;

    private Carbon $thisStart;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');

        // THE CLOCK IS FROZEN ON PURPOSE (payroll audit 2026-08-04).
        $this->travelTo(Carbon::parse('2026-06-17 12:00', $tz));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get(); // seeds the full default ladder

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $user = User::factory()->create();
        $this->employee = Employee::create([
            'user_id' => $user->id, 'department_id' => $dept->id, 'employee_no' => 'E-001', 'job_title' => 'Staff',
            'hire_date' => '2020-01-01', 'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => WorkSchedule::first()->id,
        ]);

        $this->prevStart = Carbon::now($tz)->subMonthNoOverflow()->startOfMonth();
        $this->thisStart = Carbon::now($tz)->startOfMonth();
        foreach (array_merge($this->weekdays($this->prevStart, 3), $this->weekdays($this->thisStart, 2)) as $day) {
            $this->lateRow($day);
        }
    }

    /** @return list<Carbon> */
    private function weekdays(Carbon $start, int $n): array
    {
        $out = []; $d = $start->copy();
        while (count($out) < $n) {
            if ($d->isWeekday()) { $out[] = $d->copy(); }
            $d->addDay();
        }

        return $out;
    }

    private function lateRow(Carbon $day): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $day->toDateString(),
            'time_in' => Carbon::parse($day->toDateString().' 09:00', $tz)->utc(),
            'time_out' => Carbon::parse($day->toDateString().' 17:00', $tz)->utc(),
            'status' => 'late', 'lunch_minutes' => 60, 'break_minutes' => 0, 'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    /** @return list<string> this month's late severities, oldest first */
    private function thisMonthLateSeverities(): array
    {
        return app(ViolationService::class)
            ->list($this->employee->id, $this->thisStart->format('Y-m'), ['per_page' => 100])
            ->getCollection()
            ->where('type', 'late')
            ->sortBy(fn ($v) => $v->work_date->toDateString())
            ->pluck('severity')->values()->all();
    }

    public function test_reset_on_keeps_each_month_fresh(): void
    {
        AttendancePolicy::query()->update(['monthly_late_reset' => true]);
        $vs = app(ViolationService::class);
        $vs->recompute($this->prevStart->format('Y-m'));
        $vs->recompute($this->thisStart->format('Y-m'));

        // This month starts at 0 → lates #1 and #2 → both green.
        $this->assertSame(['green', 'green'], $this->thisMonthLateSeverities());
    }

    public function test_reset_off_carries_the_tally_over(): void
    {
        AttendancePolicy::query()->update(['monthly_late_reset' => false]);
        $vs = app(ViolationService::class);
        $vs->recompute($this->prevStart->format('Y-m'));
        $vs->recompute($this->thisStart->format('Y-m'));

        // Seeded from 3 prior lates → this month's lates are #4 and #5 → orange (orange@4).
        $this->assertSame(['orange', 'orange'], $this->thisMonthLateSeverities());
    }

    /**
     * The carry-over seed is fetched for a whole CHUNK of employees in one query, bounded by the
     * EARLIEST hire date in that chunk — so a long-tenured colleague's window drags in rows that
     * fall before another employee's own floor. Each employee must still be seeded only from lates
     * on or after THEIR floor.
     *
     * The case that makes this real is a rehire: `hire_date` is reset to the new stint's start
     * while the previous stint's attendance stays on file. Without the per-employee trim they come
     * back already several steps up the escalation ladder, carrying tardiness from an employment
     * that has already been settled.
     */
    public function test_lates_before_an_employees_own_hire_date_do_not_seed_their_tally(): void
    {
        AttendancePolicy::query()->update(['monthly_late_reset' => false]);

        $tz = config('app.timezone_local');
        $prevWeekdays = $this->weekdays($this->prevStart, 4);

        // Hired PART-WAY THROUGH the previous month, so the seed block runs for them (their floor
        // is before this month) — but the three lates below sit before that floor.
        $rehired = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Staff',
            'hire_date' => $prevWeekdays[3]->toDateString(),
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => WorkSchedule::first()->id,
        ]);

        $late = function (Employee $emp, Carbon $day) use ($tz) {
            Attendance::create([
                'employee_id' => $emp->id, 'work_date' => $day->toDateString(),
                'time_in' => Carbon::parse($day->toDateString().' 09:00', $tz)->utc(),
                'time_out' => Carbon::parse($day->toDateString().' 17:00', $tz)->utc(),
                'status' => 'late', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        };

        // Three lates from the PREVIOUS stint — all before this employee's current hire date.
        foreach (array_slice($prevWeekdays, 0, 3) as $day) {
            $late($rehired, $day);
        }
        foreach ($this->weekdays($this->thisStart, 2) as $day) {
            $late($rehired, $day);
        }

        $vs = app(ViolationService::class);
        $vs->recompute($this->prevStart->format('Y-m'));
        $vs->recompute($this->thisStart->format('Y-m'));

        $severities = $vs->list($rehired->id, $this->thisStart->format('Y-m'), ['per_page' => 100])
            ->getCollection()
            ->where('type', 'late')
            ->sortBy(fn ($v) => $v->work_date->toDateString())
            ->pluck('severity')->values()->all();

        // Seeded from 0, not 3: this stint's lates are #1 and #2 → both green.
        // Without the per-employee trim they would be #4 and #5 → both orange.
        $this->assertSame(['green', 'green'], $severities);

        // The tenured employee is untouched — still seeded from their own three.
        $this->assertSame(['orange', 'orange'], $this->thisMonthLateSeverities());
    }

    public function test_resolving_prior_violations_reduces_the_carried_tally(): void
    {
        AttendancePolicy::query()->update(['monthly_late_reset' => false]);
        $vs = app(ViolationService::class);
        $vs->recompute($this->prevStart->format('Y-m'));
        $vs->recompute($this->thisStart->format('Y-m'));

        foreach ($vs->list($this->employee->id, $this->prevStart->format('Y-m'), ['per_page' => 100])
            ->getCollection()->where('type', 'late') as $v) {
            $vs->resolve($v);
        }
        $vs->recompute($this->thisStart->format('Y-m'));

        // Prior 3 resolved → carry seed drops to 0 → this month's lates are #1 and #2 → green.
        $this->assertSame(['green', 'green'], $this->thisMonthLateSeverities());
    }
}
