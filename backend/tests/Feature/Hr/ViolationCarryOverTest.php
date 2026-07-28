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
 * Slice D (2026-07-27): the late tally can carry across months (`monthly_late_reset` OFF), and a
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
            ->list($this->employee->id, $this->thisStart->format('Y-m'))
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

    public function test_resolving_prior_violations_reduces_the_carried_tally(): void
    {
        AttendancePolicy::query()->update(['monthly_late_reset' => false]);
        $vs = app(ViolationService::class);
        $vs->recompute($this->prevStart->format('Y-m'));
        $vs->recompute($this->thisStart->format('Y-m'));

        foreach ($vs->list($this->employee->id, $this->prevStart->format('Y-m'))->where('type', 'late') as $v) {
            $vs->resolve($v);
        }
        $vs->recompute($this->thisStart->format('Y-m'));

        // Prior 3 resolved → carry seed drops to 0 → this month's lates are #1 and #2 → green.
        $this->assertSame(['green', 'green'], $this->thisMonthLateSeverities());
    }
}
