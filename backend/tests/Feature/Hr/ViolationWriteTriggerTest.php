<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\ViolationService;
use App\Modules\Hr\Services\WorkSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Item 11 — violations came off the read path. The attendance board used to recompute the whole
 * roster's flags for the whole month on every GET, and it polls every 60 seconds; the violations
 * page went further and rebuilt EVERY month since tracking began before serving a page.
 *
 * The flags are now written by the events that change them. That trade only holds if two things
 * are true, and this file exists to hold them true:
 *
 *   1. a scoped recompute produces exactly what a full one would have, for that employee, and
 *      leaves everybody else's flags alone — otherwise "recompute just this person" quietly
 *      withdraws the rest of the roster;
 *   2. the reads really are reads, and each write path really does raise its own flag —
 *      otherwise the board shows yesterday's answer forever.
 */
class ViolationWriteTriggerTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $alice;

    private Employee $bob;

    private Carbon $monthStart;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');

        // Frozen mid-month on a Wednesday, so there are closed weekdays behind "today".
        $this->travelTo(Carbon::parse('2026-06-17 12:00', $tz));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get(); // seeds the full default ladder

        $this->alice = $this->makeEmployee('E-001');
        $this->bob = $this->makeEmployee('E-002');

        $this->monthStart = Carbon::now($tz)->startOfMonth();

        // Both of them are late on the same three closed weekdays, so each has a real ladder
        // to climb and there is somebody else's history for a scoped run to damage.
        foreach ($this->weekdays($this->monthStart, 3) as $day) {
            $this->lateRow($this->alice, $day);
            $this->lateRow($this->bob, $day);
        }
    }

    private function makeEmployee(string $no): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'OPS'], ['name' => 'Ops']);

        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $dept->id,
            'employee_no' => $no,
            'job_title' => 'Staff',
            'hire_date' => '2020-01-01',
            'base_salary' => 20000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
            'work_schedule_id' => WorkSchedule::first()->id,
        ]);
    }

    /** @return list<Carbon> */
    private function weekdays(Carbon $start, int $n): array
    {
        $out = [];
        $d = $start->copy();
        while (count($out) < $n) {
            if ($d->isWeekday()) {
                $out[] = $d->copy();
            }
            $d->addDay();
        }

        return $out;
    }

    private function lateRow(Employee $emp, Carbon $day): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $emp->id,
            'work_date' => $day->toDateString(),
            'time_in' => Carbon::parse($day->toDateString() . ' 09:00', $tz)->utc(),
            'time_out' => Carbon::parse($day->toDateString() . ' 17:00', $tz)->utc(),
            'status' => 'late',
            'lunch_minutes' => 60,
            'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    /** Every flag in the table, as comparable strings — the whole answer, not a summary. */
    private function snapshot(): array
    {
        return Violation::orderBy('employee_id')->orderBy('work_date')->orderBy('type')
            ->get(['employee_id', 'type', 'severity', 'work_date', 'details', 'resolved'])
            ->map(fn (Violation $v) => implode('|', [
                $v->employee_id, $v->type, $v->severity,
                $v->work_date->toDateString(), $v->details, (int) $v->resolved,
            ]))
            ->all();
    }

    private function month(): string
    {
        return $this->monthStart->format('Y-m');
    }

    // --- 1. a scoped recompute is the full one, narrowed --------------------------------

    /**
     * The claim the whole slice rests on. Scoping is only safe because employees are
     * independent in the scan — so a per-employee run must land on the SAME rows a full run
     * produces, byte for byte, not merely "similar" ones.
     */
    public function test_a_scoped_recompute_lands_exactly_where_a_full_one_would(): void
    {
        $vs = app(ViolationService::class);

        $vs->recompute($this->month());
        $full = $this->snapshot();
        $this->assertNotEmpty($full, 'precondition: the full scan produced flags to compare against');

        Violation::query()->forceDelete();

        $vs->recompute($this->month(), $this->alice->id);
        $vs->recompute($this->month(), $this->bob->id);

        $this->assertSame($full, $this->snapshot());
    }

    /**
     * The failure mode scoping invites: the stale-flag sweep runs over the month, and a scoped
     * scan only produced ONE person's incidents. Narrowed wrongly, it reads every other
     * employee's flags as "no longer produced" and withdraws the lot.
     */
    public function test_a_scoped_recompute_does_not_withdraw_anybody_elses_flags(): void
    {
        $vs = app(ViolationService::class);
        $vs->recompute($this->month());

        $bobBefore = Violation::where('employee_id', $this->bob->id)->count();
        $this->assertGreaterThan(0, $bobBefore, 'precondition: Bob has flags to lose');

        $vs->recompute($this->month(), $this->alice->id);

        $this->assertSame($bobBefore, Violation::where('employee_id', $this->bob->id)->count());
    }

    /** A resolved flag survives a scoped run, exactly as it survives a full one. */
    public function test_a_scoped_recompute_leaves_a_resolved_flag_resolved(): void
    {
        $vs = app(ViolationService::class);
        $vs->recompute($this->month());

        $flag = Violation::where('employee_id', $this->alice->id)->where('type', 'late')->firstOrFail();
        $vs->resolve($flag);

        $vs->recompute($this->month(), $this->alice->id);

        $this->assertTrue($flag->fresh()->resolved);
    }

    // --- 2. the reads are reads ---------------------------------------------------------

    /** @return list<string> the write statements a callback issued */
    private function writesDuring(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $log = DB::getRawQueryLog();
        DB::disableQueryLog();

        return collect($log)
            ->pluck('raw_query')
            ->filter(fn ($q) => (bool) preg_match('/^\s*(insert|update|delete)\b/i', (string) $q))
            ->values()->all();
    }

    /**
     * The board is the endpoint that polls. If it still writes, nothing about this slice
     * helped: a 60-second timer would keep a full-roster rescan running for as long as anyone
     * has the page open.
     */
    public function test_the_attendance_board_writes_nothing(): void
    {
        app(ViolationService::class)->recompute($this->month());

        $writes = $this->writesDuring(fn () => app(AttendanceService::class)->allForDate());

        $this->assertSame([], $writes, 'reading the roster must not write');
    }

    /** The violations list, which used to rebuild every month in history before answering. */
    public function test_the_violations_endpoint_writes_nothing(): void
    {
        app(ViolationService::class)->recompute($this->month());
        $this->actingAsHr();

        $writes = $this->writesDuring(function () {
            $this->getJson('/api/v1/hr/violations')->assertOk();
            $this->getJson('/api/v1/hr/violations?month=' . $this->month())->assertOk();
        });

        $this->assertSame([], $writes, 'listing flags must not rebuild them');
    }

    // --- 3. each write raises its own flag ----------------------------------------------

    /**
     * The live clock. This is the one the old code deliberately left to the read path — the
     * comment on recordVerifiedClockIn said so — which is exactly why it has to be covered
     * now: nothing else is going to notice this punch.
     */
    public function test_clocking_in_late_raises_the_flag_without_anyone_opening_the_board(): void
    {
        $tz = config('app.timezone_local');
        // 09:30 on the frozen day, well past the 08:00 start with zero grace.
        $this->travelTo(Carbon::parse('2026-06-17 09:30', $tz));

        $before = Violation::where('employee_id', $this->alice->id)
            ->whereDate('work_date', '2026-06-17')->count();
        $this->assertSame(0, $before, 'precondition: today carries no flag yet');

        app(AttendanceService::class)->clockIn($this->alice);

        $this->assertSame(
            1,
            Violation::where('employee_id', $this->alice->id)
                ->where('type', 'late')->whereDate('work_date', '2026-06-17')->count(),
            'the punch itself must record the flag',
        );
    }

    /**
     * The bug the read path was hiding, not just a port of it: LeaveService recomputed with no
     * month, meaning THIS month. Approving leave for a past month therefore moved nothing, and
     * only survived because opening the violations page rebuilt all of history on the way in.
     * With that gone, an approval has to reach the month it actually covers.
     */
    public function test_approving_leave_for_a_past_month_clears_that_months_absence(): void
    {
        $tz = config('app.timezone_local');
        $vs = app(ViolationService::class);

        // A closed weekday in the PREVIOUS month that Alice simply did not work.
        $absentDay = Carbon::parse('2026-05-13', $tz); // a Wednesday
        $prevMonth = $absentDay->format('Y-m');

        // Tracking has to predate the day, or the scan will not judge it at all.
        $this->lateRow($this->alice, Carbon::parse('2026-05-11', $tz));

        $vs->recompute($prevMonth);
        $this->assertSame(
            1,
            Violation::where('employee_id', $this->alice->id)
                ->whereDate('work_date', $absentDay->toDateString())
                ->whereIn('type', ['ua', 'awol'])->count(),
            'precondition: the unexcused day is flagged',
        );

        $type = LeaveType::firstOrCreate(
            ['name' => 'Vacation Leave'],
            ['code' => 'VL', 'days_per_year' => 15, 'is_paid' => true, 'requires_approval' => true],
        );

        $request = LeaveRequest::create([
            'employee_id' => $this->alice->id,
            'leave_type_id' => $type->id,
            'start_date' => $absentDay->toDateString(),
            'end_date' => $absentDay->toDateString(),
            'days' => 1,
            'reason' => 'Filed late',
            'status' => 'pending',
        ]);

        app(LeaveService::class)->approve($request, $this->hrUser());

        $this->assertSame(
            0,
            Violation::where('employee_id', $this->alice->id)
                ->whereDate('work_date', $absentDay->toDateString())
                ->whereIn('type', ['ua', 'awol'])->count(),
            'the approval must reach the month the leave is IN, not the month it was approved in',
        );
    }

    /**
     * A suspension is a ROSTER-wide input: HR cancels work on a date and nobody who stayed
     * home was absent. Unlike the live clock this cannot be scoped to one employee, so it
     * rebuilds the month for everyone — at the write, not on the next read.
     */
    public function test_declaring_a_suspension_clears_the_absences_on_that_date(): void
    {
        $tz = config('app.timezone_local');
        $vs = app(ViolationService::class);

        // A closed weekday this month that neither of them has a record for.
        $emptyDay = Carbon::parse('2026-06-16', $tz); // the Tuesday before "today"
        $vs->recompute($this->month());

        $this->assertGreaterThan(
            0,
            Violation::whereDate('work_date', $emptyDay->toDateString())
                ->whereIn('type', ['ua', 'awol'])->count(),
            'precondition: the untracked workday reads as an absence',
        );

        app(WorkSuspensionService::class)->declare([
            'date' => $emptyDay->toDateString(),
            'name' => 'Typhoon Signal No. 2',
            'is_paid' => true,
        ], $this->hrUser());

        $this->assertSame(
            0,
            Violation::whereDate('work_date', $emptyDay->toDateString())
                ->whereIn('type', ['ua', 'awol'])->count(),
            'nobody was expected in, so the declaration must withdraw the flags at the write',
        );
    }
}
