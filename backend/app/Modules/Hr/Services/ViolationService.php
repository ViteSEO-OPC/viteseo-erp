<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\Violation;
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
    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly AttendancePolicyService $policy,
        private readonly HolidayService $holidays,
        private readonly WorkSuspensionService $suspensions,
    ) {}

    /**
     * @param  string|null  $month  'YYYY-MM' — restrict to that calendar month (Manila).
     */
    public function list(?int $employeeId = null, ?string $month = null): Collection
    {
        return Violation::with('employee.user')
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($month, function ($q) use ($month) {
                [$start, $end] = $this->monthBounds($month);
                $q->whereBetween('work_date', [$start, $end]);
            })
            ->orderBy('resolved') // unresolved (0) first, then resolved (1)
            ->orderByDesc('work_date')
            ->get();
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
     */
    public function recompute(?string $month = null): void
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

        // The colour ladder HR configures (Company → Rules). Constant across this month's
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

        // Step D: a resolved LATE violation has been actioned by HR, so it never counts toward
        // escalation — not in this month, not in the carry-over seed. Keyed "employeeId|Y-m-d"
        // across all history up to the scan end.
        $resolvedLate = [];
        foreach (
            Violation::where('type', 'late')->where('resolved', true)
                ->whereDate('work_date', '<=', $scanEnd->toDateString())
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
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        $computed = [];

        foreach (Employee::with('workSchedule')->get() as $emp) {
            $rows = Attendance::where('employee_id', $emp->id)
                ->whereBetween('work_date', [$monthStart->toDateString(), $scanEnd->toDateString()])
                ->get()
                ->keyBy(fn ($r) => $r->work_date->toDateString());

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
                $priorLates = Attendance::where('employee_id', $emp->id)
                    ->where('status', 'late')
                    ->whereBetween('work_date', [$carryFloor, $monthStart->copy()->subDay()->toDateString()])
                    ->pluck('work_date');
                foreach ($priorLates as $wd) {
                    if (! isset($resolvedLate["{$emp->id}|" . $wd->toDateString()])) {
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

        $this->sync($computed, $monthStart->toDateString(), $monthEnd->toDateString());
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
     * @param  array<int, array<string, mixed>>  $computed
     */
    private function sync(array $computed, string $monthStart, string $monthEnd): void
    {
        $keys = [];
        foreach ($computed as $c) {
            Violation::updateOrCreate(
                ['employee_id' => $c['employee_id'], 'type' => $c['type'], 'work_date' => $c['work_date']],
                ['severity' => $c['severity'], 'details' => $c['details']],
            );
            $keys[] = "{$c['employee_id']}|{$c['type']}|{$c['work_date']}";
        }

        Violation::where('resolved', false)
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->get()
            ->each(function (Violation $v) use ($keys) {
                $key = "{$v->employee_id}|{$v->type}|" . $v->work_date->toDateString();
                if (! in_array($key, $keys, true)) {
                    $v->delete();
                }
            });
    }
}
