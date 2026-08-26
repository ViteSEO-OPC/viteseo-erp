<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\Violation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Turns raw attendance (+ each employee's schedule + the attendance policy) into
 * color-coded violation flags, per the brief's legend:
 *   green  — 1st–2nd tardiness in a month; 1st missed in/out
 *   yellow — 3rd tardiness; 1-day unauthorised absence (UA)
 *   orange — 3 consecutive lates / 4th tardiness; 2 consecutive UA days
 *   red    — 3+ consecutive days without notice (AWOL)
 */
class ViolationService
{
    /** Employees per scan chunk — bounds both round-trips and the attendance held in memory. */
    private const SCAN_CHUNK = 500;

    /** Rows per bulk violation upsert; keeps the statement (and its placeholder count) sane. */
    private const UPSERT_CHUNK = 500;

    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly AttendancePolicyService $policy,
        private readonly HolidayService $holidays,
        private readonly WorkSuspensionService $suspensions,
    ) {}

    /**
     * @param  string|null  $month  'YYYY-MM' — restrict to that calendar month (Manila).
     */
    /**
     * One page of the flag history, unresolved first and newest within that.
     *
     * The employee and month narrowing happen in the QUERY, which matters more here than it
     * looks: the record dialog offers a month/year picker, and filtering the rows already in
     * hand would search whichever page the reader happened to be on.
     *
     * Unresolved-first is the priority rung expressed on a boolean rather than a status string,
     * so it is written out here rather than borrowed from PagesQueues — a flag HR has actioned
     * sinks below one still waiting, whatever their dates.
     *
     * @param  array<string, mixed>  $filters  page, per_page
     */
    public function list(?int $employeeId = null, ?string $month = null, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 10)));

        return Violation::with('employee.user')
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($month, function ($q) use ($month) {
                [$start, $end] = $this->monthBounds($month);
                $q->whereBetween('work_date', [$start, $end]);
            })
            ->orderBy('resolved') // unresolved (0) first, then resolved (1)
            ->orderByDesc('work_date')
            // A unique tiebreaker. Several flags can share a work_date — a late and a long
            // break on the same day — and a tied ORDER BY has no defined answer, so without
            // this a row could appear on two pages or on neither.
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function resolve(Violation $violation): Violation
    {
        $violation->update(['resolved' => true]);

        return $violation;
    }

    /**
     * Undo a resolve, putting the flag back in play. The row itself is never
     * recomputed away, so this simply re-arms it for the escalation ladder.
     */
    public function unresolve(Violation $violation): Violation
    {
        $violation->update(['resolved' => false]);

        return $violation;
    }

    /**
     * Worst unresolved flag + count per employee, for the roster. `work_date` is the
     * date of the violation DRIVING the severity (worst severity, most recent on a
     * tie) — the roster flag can point to a past month, so the detail modal uses this
     * to open on the right month instead of blindly the current one.
     *
     * @return array<int, array{severity:string, count:int, types:array<int,string>, work_date:string}>
     */
    public function flagsByEmployee(): array
    {
        return Violation::where('resolved', false)
            ->get()
            ->groupBy('employee_id')
            ->map(function ($group) {
                // Stable sort: latest-first, then by severity — so `first()` is the
                // worst, breaking ties toward the most recent occurrence.
                $worst = $group
                    ->sortByDesc(fn ($v) => $v->work_date->timestamp)
                    ->sortByDesc(fn ($v) => Violation::SEVERITY_RANK[$v->severity] ?? 0)
                    ->first();

                return [
                    'severity' => $worst->severity,
                    'count' => $group->count(),
                    'types' => $group->pluck('type')->unique()->values()->all(),
                    'work_date' => $worst->work_date->toDateString(),
                ];
            })
            ->all();
    }

    /**
     * Regenerate violations across all employees for one calendar month (Manila).
     * Defaults to the current month. Streak/late-count logic is per-month, so each
     * month is self-contained — recomputing one month never touches another's rows,
     * which is what lets violation history accumulate across months.
     *
     * @param  string|null  $month  'YYYY-MM' — the month to (re)compute; null = current.
     * @param  int|null  $employeeId  Narrow the scan to ONE employee; null = the whole roster.
     *                                Employees are independent here — every streak counter
     *                                resets per employee and no rung reads another person's
     *                                record — so a scoped run produces exactly the rows a full
     *                                run would produce for that employee, and touches nobody
     *                                else's. This is what lets a clock-in recompute its own
     *                                flags without dragging the roster through a rescan.
     */
    public function recompute(?string $month = null, ?int $employeeId = null): void
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->startOfDay();

        $monthStart = $month
            ? Carbon::parse("{$month}-01", $tz)->startOfMonth()
            : $today->copy()->startOfMonth();

        if ($monthStart->gt($today)) {
            return; // a future month has nothing to compute
        }

        $monthEnd = $monthStart->copy()->endOfMonth();
        // Never scan past today — the current month is only complete up to today.
        $scanEnd = $monthEnd->gt($today) ? $today->copy() : $monthEnd;

        // The colour ladder HR configures (Company → Settings). Constant across this month's
        // scan; a null column falls back to today's literals, so this is a no-op by default.
        $policy = $this->policy->get();
        // Per-CATEGORY merge, so a category added after HR last saved this page ('s
        // `long_break`) still has a ladder instead of silently never firing.
        $rules = $this->policy->violationRules();
        // Tolerance on top of each employee's own schedule allowance, per bucket.
        // Either may be 0, meaning no tolerance at all.
        $lunchGrace = (int) $policy->lunch_grace_minutes;
        $breakGrace = (int) $policy->break_grace_minutes;

        // Step D: `monthly_late_reset` OFF ⇒ the LATE tally CARRIES OVER month to month (it only
        // comes down when HR resolves a violation). ON (default) ⇒ each month starts at zero.
        $carryOver = ! $policy->monthly_late_reset;

        // The company rule behind the management exemption, read ONCE for the whole scan: with
        // it off nobody is exempt and management is flagged like everyone else.
        $managementAuto = (bool) $policy->management_auto_attendance;

        // Step D: a resolved LATE violation has been actioned by HR, so it never counts toward
        // escalation — not in this month, not in the carry-over seed. Keyed "employeeId|Y-m-d"
        // across all history up to the scan end.
        $resolvedLate = [];
        foreach (
            Violation::where('type', 'late')->where('resolved', true)
                ->whereDate('work_date', '<=', $scanEnd->toDateString())
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->get(['employee_id', 'work_date']) as $rv
        ) {
            $resolvedLate["{$rv->employee_id}|" . $rv->work_date->toDateString()] = true;
        }

        // The earliest HUMAN-ENTERED attendance record anywhere is our proxy for when the
        // org began tracking attendance — nobody can be "absent" for a day before tracking
        // existed. (Swap for an explicit go-live/config date if one is ever added.)
        $trackingStart = Attendance::where('source', Attendance::SOURCE_MANUAL)->min('work_date');

        // Non-working holidays in the scanned month. Nobody is expected in, so a
        // holiday can neither be an absence nor break an absence streak — it is skipped
        // exactly like a rest day. `special_working` and unclassified (NULL) types are not
        // in this map, so those still behave as ordinary workdays.
        $holidays = array_filter(
            $this->holidays->typedHolidaysBetween($monthStart, $scanEnd),
            fn ($type) => in_array($type, HolidayService::NON_WORKING_TYPES, true),
        );

        // HR-declared non-working days in the same window. Kept separate from the holiday map
        // rather than merged into it: the two answer different questions downstream (a holiday
        // has a legal class and a premium; a suspension has neither), and one map serving both
        // is how a suspension eventually gets priced as a holiday by accident.
        $suspensions = $this->suspensions->betweenAsMap(
            $monthStart->toDateString(),
            $scanEnd->toDateString(),
        );

        // Approved leaves overlapping the scanned range, grouped by employee.
        $leaves = LeaveRequest::where('status', 'approved')
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->whereDate('start_date', '<=', $scanEnd->toDateString())
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        // The day loop below asks for each date's rule overrides once per employee-per-day. One
        // range read up front turns that whole month of lookups into a single query.
        $this->schedule->warmDailyOverrides($monthStart, $scanEnd);

        $computed = [];

        foreach ($this->rosterWithAttendance(
            $monthStart->toDateString(),
            $scanEnd->toDateString(),
            // Only pay for the carry-over seed when the carry-over is actually on.
            $carryOver ? $monthStart->copy()->subDay()->toDateString() : null,
            $trackingStart,
            $employeeId,
        ) as [$emp, $rows, $priorLateDates]) {
            $lateStreak = 0;
            $absentStreak = 0;
            $monthlyLate = 0;
            $monthlyMissedClockout = 0;
            $monthlyLongBreak = 0;

            // Carry-over seed (step D): with the monthly reset OFF, this month's late tally starts
            // from the count of PRIOR *unresolved* lates (since hire / tracking start), so the
            // colour keeps climbing across months until HR resolves. Self-contained per call, so
            // recomputing one month in isolation still reconstructs the running total.
            $carryFloor = max(array_filter([$emp->hire_date?->toDateString(), $trackingStart]));
            if ($carryOver && $carryFloor && $carryFloor < $monthStart->toDateString()) {
                foreach ($priorLateDates as $wdStr) {
                    // The chunk's query used the earliest floor in the chunk, so trim to THIS
                    // employee's own — anything earlier predates their hire or the tracking start.
                    if ($wdStr < $carryFloor) {
                        continue;
                    }

                    // A stray 'late' row inside an exempt period is not a tardiness — the same
                    // rule the day loop applies, so the seed and the scan agree.
                    if ($emp->managementExemptOn($wdStr, $managementAuto)) {
                        continue;
                    }

                    if (! isset($resolvedLate["{$emp->id}|{$wdStr}"])) {
                        $monthlyLate++;
                    }
                }
            }

            // An absence can't predate the employee's hire, the org's tracking start,
            // or the scanned month. Below this floor, a missing record is "not tracked",
            // not an absence — otherwise every empty pre-hire/pre-go-live day is an AWOL.
            $absenceFloor = max(array_filter([
                $monthStart->toDateString(),
                $emp->hire_date?->toDateString(),
                $trackingStart,
            ]));

            for ($d = $monthStart->copy(); $d->lte($scanEnd); $d->addDay()) {
                $eff = $this->schedule->effectiveFor($emp, $d);
                if (! $eff['is_workday']) {
                    continue; // rest day — never a violation
                }

                $dateStr = $d->toDateString();

                // Management is exempt from the time clock, so no violation category applies to
                // the days they were exempt for: those rows are written by
                // `hr:generate-management-attendance`, not scanned. Guarding HERE rather than
                // relying on those rows existing is the point — a night the job did not run left
                // a missing row, and the absence branch below reads a missing row as an
                // unauthorised absence, escalating to red AWOL beside a roster that says
                // 'Present'. Scoped BY DATE, because the exemption starts when the promotion
                // does: skipping the employee outright erased every real late and absence they
                // accrued as rank-and-file the moment HR ticked the box.
                if ($emp->managementExemptOn($dateStr, $managementAuto)) {
                    $lateStreak = 0;
                    $absentStreak = 0;

                    continue;
                }

                $row = $rows->get($dateStr);

                // Non-working holiday with no clock-in: not a violation, and it leaves the
                // streaks untouched (a holiday in the middle of an absence run neither
                // resets it nor extends it). Someone who DID work falls through, so a late
                // arrival on a worked holiday is still flagged.
                if (isset($holidays[$dateStr]) && ! ($row && $row->time_in !== null)) {
                    continue;
                }

                // A day HR cancelled — typhoon, shutdown — behaves exactly the same way, and
                // for both halves of the decision: PAID or UNPAID, nobody was expected in, so
                // not coming in is not a violation. (The unpaid half costs a day's pay in
                // payroll; that is a pay consequence, not a disciplinary one.) Whoever DID work
                // falls through, so a late arrival on a skeleton crew is still flagged.
                if (isset($suspensions[$dateStr]) && ! ($row && $row->time_in !== null)) {
                    continue;
                }

                // Attendance is the source of truth for flags, and an HR override is
                // the strongest form of it. A day HR marked on-leave / rest-day / holiday raises
                // nothing and clears the streaks; `absent` and `late` fall through so they still
                // flag. Without this, correcting a record left its old flag standing.
                if ($row && $row->status_override && in_array($row->status, Attendance::PAID_STATUSES, true)) {
                    if ($row->status !== 'late') {
                        $lateStreak = 0;
                        $absentStreak = 0;

                        continue;
                    }
                }

                $isPast = $d->lt($today);
                $onLeave = $this->onLeave($leaves->get($emp->id), $dateStr);
                $clockedIn = $row && $row->time_in !== null;

                if ($row && $row->status === 'late') {
                    $absentStreak = 0;

                    // A resolved late has been actioned by HR — it doesn't count toward the tally
                    // and isn't re-flagged (step D). The resolved row is preserved by sync().
                    if (isset($resolvedLate["{$emp->id}|{$dateStr}"])) {
                        $lateStreak = 0;
                    } else {
                        $monthlyLate++;
                        $lateStreak++;

                        // Severity comes from HR's `late` ladder (streak_min / monthly_min rungs).
                        $match = self::ladderMatch($rules, 'late', ['streak' => $lateStreak, 'monthly' => $monthlyLate]);
                        if ($match) {
                            $scope = $carryOver ? 'running total' : 'this month';
                            $computed[] = $this->incident($emp->id, 'late', $match['severity'], $dateStr, "Late arrival (#{$monthlyLate} {$scope})");
                        }
                    }
                } elseif ($isPast && ! $onLeave && ! $clockedIn && $dateStr >= $absenceFloor) {
                    // Unauthorised absence: a past scheduled workday (at/after the org's
                    // tracking start and the employee's hire date) with no approved leave
                    // and no clock-in — whether HR explicitly marked it 'absent' OR the
                    // record is simply missing. Mirrors the roster and payroll, which both
                    // treat an untracked past workday as an absence.
                    $absentStreak++;
                    $lateStreak = 0;

                    // The `absence` ladder decides the colour AND the type ('awol' at the red
                    // rung, 'ua' below) — so the AWOL threshold is just that rung's streak_min.
                    $match = self::ladderMatch($rules, 'absence', ['streak' => $absentStreak]);
                    if ($match) {
                        $type = $match['type'] ?? 'ua';
                        $note = $type === 'awol'
                            ? "AWOL — {$absentStreak} consecutive days without notice"
                            : 'Unauthorised absence' . ($absentStreak >= 2 ? " ({$absentStreak} consecutive days)" : '');
                        $computed[] = $this->incident($emp->id, $type, $match['severity'], $dateStr, $note);
                    }
                } else {
                    // Present / on-time / on approved leave — clears the streaks.
                    $lateStreak = 0;
                    $absentStreak = 0;

                    if ($row && $row->time_in && ! $row->time_out && $isPast) {
                        // Auto-credited to the scheduled end for PAY (no undertime, never OT);
                        // here it's a disciplinary flag whose colour escalates by the monthly
                        // count (step B), the same way lateness does.
                        $monthlyMissedClockout++;
                        $match = self::ladderMatch($rules, 'missed_time_out', ['occurrence' => $monthlyMissedClockout]);
                        if ($match) {
                            $note = "Clocked in but never clocked out (#{$monthlyMissedClockout} this month)";
                            $computed[] = $this->incident($emp->id, 'missed_time_out', $match['severity'], $dateStr, $note);
                        }
                    }
                }

                // break/lunch overage. Measured against the EMPLOYEE'S OWN entitlement
                // (schedule unpaid + paid break) plus the company tolerance, not a flat company
                // ceiling: a 30-minute-lunch schedule and a 60-minute one can't share one number.
                // Checked outside the late/absent branches because a long break is orthogonal to
                // both, and computed here rather than in DeductionService so it works with every
                // deduction mode off. Flag only — a long break never costs pay.
                if ($row && $row->time_in) {
                    // Lunch and break are judged SEPARATELY, each against its own schedule
                    // allowance and its own tolerance. Pooling them let a short lunch bankroll
                    // a long break — 20 + 60 totals the same 80 as 60 + 20, so a break running
                    // 3× its allowance never surfaced.
                    $over = [];
                    foreach ([
                        ['Lunch', (int) $row->lunch_minutes, (int) ($eff['unpaid_break_minutes'] ?? 0), $lunchGrace],
                        ['Break', (int) $row->break_minutes, (int) ($eff['paid_break_minutes'] ?? 0), $breakGrace],
                    ] as [$name, $taken, $allowed, $grace]) {
                        // Strictly greater than, so a grace of 0 means exactly the allowance
                        // still passes and one minute over is flagged.
                        if ($taken > $allowed + $grace) {
                            $over[] = "{$name} ran " . ($taken - $allowed) . " min over the {$allowed} min allowance ({$taken} min taken)";
                        }
                    }

                    if ($over !== []) {
                        $monthlyLongBreak++;
                        $match = self::ladderMatch($rules, 'long_break', ['occurrence' => $monthlyLongBreak]);
                        if ($match) {
                            $computed[] = $this->incident(
                                $emp->id, 'long_break', $match['severity'], $dateStr,
                                implode(' · ', $over) . " · #{$monthlyLongBreak} this month",
                            );
                        }
                    }
                }
            }
        }

        $this->sync($computed, $monthStart->toDateString(), $monthEnd->toDateString(), $employeeId);
    }

    /**
     * Build the full violation history: recompute every month from the org's tracking
     * start (earliest human-entered attendance record) up to the current month. Idempotent
     * and persisted, so the "All time" history view has data for every month, not just the
     * ones that happen to have been opened. No real attendance anywhere → nothing to do.
     */
    public function recomputeHistory(): void
    {
        $tz = config('app.timezone_local');
        $earliest = Attendance::where('source', Attendance::SOURCE_MANUAL)->min('work_date');
        if (! $earliest) {
            return;
        }

        $month = Carbon::parse($earliest, $tz)->startOfMonth();
        $current = now($tz)->startOfMonth();

        while ($month->lte($current)) {
            $this->recompute($month->format('Y-m'));
            $month->addMonth();
        }
    }

    /**
     * Recompute every month a date range touches. Streak logic is per-month and self-contained,
     * so a range that spans a boundary needs each of its months rebuilt — recomputing only the
     * month the range STARTS in leaves the tail asserting the old answer.
     *
     * The caller passes the dates of the thing that changed (a leave's span, a suspension's
     * span, a holiday's date), not a guess at "this month": approving a leave for last week
     * after the month turned must move LAST month's flags, and the read path is no longer
     * there to paper over it.
     */
    public function recomputeRange(string $from, string $to, ?int $employeeId = null): void
    {
        $tz = config('app.timezone_local');
        $month = Carbon::parse($from, $tz)->startOfMonth();
        $last = Carbon::parse($to, $tz)->startOfMonth();

        // Guard a reversed range rather than looping forever on it.
        if ($month->gt($last)) {
            return;
        }

        while ($month->lte($last)) {
            $this->recompute($month->format('Y-m'), $employeeId);
            $month->addMonth();
        }
    }

    // --- helpers ---

    /**
     * Inclusive 'Y-m-d' [start, end] of a 'YYYY-MM' month in Manila.
     *
     * @return array{0:string, 1:string}
     */
    private function monthBounds(string $month): array
    {
        $tz = config('app.timezone_local');
        $start = Carbon::parse("{$month}-01", $tz)->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    /**
     * The first ladder rung (top-down, most-severe first) whose thresholds are ALL met.
     * A rung matches when every threshold it declares (streak_min / monthly_min /
     * occurrence_min) is satisfied by the context. Returns null when no rung matches —
     * which a category with an empty ladder uses to mean "never flag this".
     *
     * @param  array<string, array<int, array<string, mixed>>>  $rules
     * @param  array{streak?:int, monthly?:int, occurrence?:int}  $ctx
     * @return array{severity:string, type:?string}|null
     */
    public static function ladderMatch(array $rules, string $category, array $ctx): ?array
    {
        foreach ($rules[$category] ?? [] as $rung) {
            if (isset($rung['streak_min']) && ($ctx['streak'] ?? 0) < (int) $rung['streak_min']) {
                continue;
            }
            if (isset($rung['monthly_min']) && ($ctx['monthly'] ?? 0) < (int) $rung['monthly_min']) {
                continue;
            }
            if (isset($rung['occurrence_min']) && ($ctx['occurrence'] ?? 0) < (int) $rung['occurrence_min']) {
                continue;
            }

            return ['severity' => $rung['severity'], 'type' => $rung['type'] ?? null];
        }

        return null;
    }

    /** @return array{employee_id:int, type:string, severity:string, work_date:string, details:string} */
    /**
     * The roster paired with its attendance for the scan window, walked in CHUNKS.
     *
     * Replaces one attendance query PER EMPLOYEE — which made a recompute O(headcount) in
     * round-trips, and it runs on every read of the attendance board. Chunking rather than one
     * big prefetch is deliberate: fetching every employee and every attendance row for the month
     * in one go would trade those round-trips for an O(headcount × days) memory footprint, which
     * is the worse failure at scale. Each chunk costs exactly two queries regardless of size.
     *
     * Keyed by RANGE, not per employee: a per-employee memo looks fine in isolation and still
     * degrades to one query each in a full-roster run.
     *
     * Employees are independent here — every streak counter resets at the top of the loop — so
     * the chunk boundary cannot affect the result.
     *
     * @return \Generator<int, array{0: Employee, 1: \Illuminate\Support\Collection<string, Attendance>}>
     */
    private function rosterWithAttendance(
        string $from,
        string $to,
        ?string $priorLatesUntil = null,
        ?string $trackingStart = null,
        ?int $employeeId = null,
    ): \Generator {
        $lastId = 0;

        while (true) {
            $chunk = Employee::with('workSchedule')
                ->where('id', '>', $lastId)
                ->when($employeeId, fn ($q) => $q->whereKey($employeeId))
                ->orderBy('id')
                ->limit(self::SCAN_CHUNK)
                ->get();

            if ($chunk->isEmpty()) {
                return;
            }

            $lastId = $chunk->last()->id;

            $attendance = Attendance::whereIn('employee_id', $chunk->modelKeys())
                ->whereBetween('work_date', [$from, $to])
                ->get()
                ->groupBy('employee_id');

            yield from $this->pairWithAttendance(
                $chunk,
                $attendance,
                $this->priorLateDates($chunk, $priorLatesUntil, $trackingStart),
            );
        }
    }

    /**
     * Dates each employee in the chunk was late BEFORE the scanned month — the carry-over seed,
     * in one query for the chunk instead of one per employee.
     *
     * Only fetched when the caller asks (carry-over is off by default, and then this costs
     * nothing). The lower bound is the EARLIEST floor anyone in the chunk has; each employee's
     * own floor is applied by the caller, so one query covers every individual window without
     * dragging in history that predates the org's tracking start.
     *
     * Read through `toBase()`: this needs two columns to count with, not hydrated models, and a
     * long-tenured roster's whole late history is a lot of objects to build and throw away.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Employee>  $chunk
     * @return array<int, list<string>>
     */
    private function priorLateDates($chunk, ?string $until, ?string $trackingStart): array
    {
        if ($until === null) {
            return [];
        }

        $floor = max(array_filter([
            $chunk->min(fn (Employee $e) => $e->hire_date?->toDateString()),
            $trackingStart,
        ]));

        $rows = Attendance::where('status', 'late')
            ->whereIn('employee_id', $chunk->modelKeys())
            ->where('work_date', '<=', $until)
            ->when($floor, fn ($q) => $q->where('work_date', '>=', $floor))
            ->toBase()
            ->get(['employee_id', 'work_date']);

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row->employee_id][] = substr((string) $row->work_date, 0, 10);
        }

        return $dates;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Employee>  $chunk
     * @param  array<int, list<string>>  $priorLates
     */
    private function pairWithAttendance($chunk, $attendance, array $priorLates): \Generator
    {
        foreach ($chunk as $emp) {
            yield [
                $emp,
                ($attendance[$emp->id] ?? collect())
                    ->keyBy(fn (Attendance $r) => $r->work_date->toDateString()),
                $priorLates[$emp->id] ?? [],
            ];
        }
    }

    private function incident(int $employeeId, string $type, string $severity, string $date, string $details): array
    {
        return compact('type', 'severity', 'details') + [
            'employee_id' => $employeeId,
            'work_date' => $date,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LeaveRequest>|null  $intervals
     */
    private function onLeave($intervals, string $date): bool
    {
        if (! $intervals) {
            return false;
        }
        foreach ($intervals as $leave) {
            if ($date >= $leave->start_date->toDateString() && $date <= $leave->end_date->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upsert computed incidents and drop stale unresolved ones. updateOrCreate
     * never touches `resolved`, so an HR-resolved row stays resolved. The stale-row
     * cleanup is scoped to [$monthStart, $monthEnd] so recomputing one month never
     * deletes another month's history.
     *
     * When $employeeId is set the sweep narrows to that person as well: a scoped scan only
     * produced their incidents, so without the narrowing it would read every OTHER employee's
     * flags for the month as "no longer produced" and withdraw the lot.
     *
     * @param  array<int, array<string, mixed>>  $computed
     */
    private function sync(array $computed, string $monthStart, string $monthEnd, ?int $employeeId = null): void
    {
        // Keyed as a SET, not a list: the survivor check below runs once per existing flag, and
        // `in_array` over a list made that O(computed × existing) — quadratic on the two numbers
        // that both grow with headcount.
        $keys = [];
        foreach ($computed as $c) {
            $keys["{$c['employee_id']}|{$c['type']}|{$c['work_date']}"] = true;
        }

        // One statement per batch instead of a SELECT + INSERT/UPDATE per incident. The unique
        // index (employee_id, type, work_date) is the same constraint updateOrCreate matched on,
        // and only severity/details are overwritten — so `resolved` survives a recompute exactly
        // as it did before, which is what keeps an HR resolution from being undone.
        foreach (array_chunk($computed, self::UPSERT_CHUNK) as $batch) {
            Violation::upsert($batch, ['employee_id', 'type', 'work_date'], ['severity', 'details']);
        }

        // Flags the scan no longer produces are withdrawn in ONE statement rather than one
        // delete per row. Violation has no observers, so nothing depended on the per-model
        // delete events.
        $stale = Violation::where('resolved', false)
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->get(['id', 'employee_id', 'type', 'work_date'])
            ->reject(fn (Violation $v) => isset($keys["{$v->employee_id}|{$v->type}|" . $v->work_date->toDateString()]))
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            Violation::whereIn('id', $stale)->delete();
        }
    }
}
