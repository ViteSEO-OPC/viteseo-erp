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
 *
 * Simplified but faithful: it scans the current month, tracks per-employee late
 * and absence streaks against their EFFECTIVE schedule workdays, and escalates
 * severity. recompute() is idempotent — one row per (employee, type, date); rows
 * HR has resolved survive a recompute.
 */
class ViolationService
{
    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly AttendancePolicyService $policy,
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

        $latesBeforeAbsence = $this->policy->get()->consecutive_lates_before_absence;

        // The earliest HUMAN-ENTERED attendance record anywhere is our proxy for when the
        // org began tracking attendance — nobody can be "absent" for a day before tracking
        // existed. (Swap for an explicit go-live/config date if one is ever added.)
        //
        // System-generated rows are excluded deliberately: a management backfill reaching
        // further back than the real records would drag this floor with it and turn every
        // untracked day before go-live into an absence for EVERY employee.
        $trackingStart = Attendance::where('source', Attendance::SOURCE_MANUAL)->min('work_date');

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
                $isPast = $d->lt($today);
                $onLeave = $this->onLeave($leaves->get($emp->id), $dateStr);
                $clockedIn = $row && $row->time_in !== null;

                if ($row && $row->status === 'late') {
                    $monthlyLate++;
                    $lateStreak++;
                    $absentStreak = 0;

                    $sev = ($lateStreak >= 3 || $monthlyLate >= 4) ? 'orange'
                        : ($monthlyLate >= 3 ? 'yellow' : 'green');
                    $note = $lateStreak > $latesBeforeAbsence
                        ? "Late #{$monthlyLate} this month — beyond {$latesBeforeAbsence} consecutive, counts as an absence"
                        : "Late arrival (#{$monthlyLate} this month)";

                    $computed[] = $this->incident($emp->id, 'late', $sev, $dateStr, $note);
                } elseif ($isPast && ! $onLeave && ! $clockedIn && $dateStr >= $absenceFloor) {
                    // Unauthorised absence: a past scheduled workday (at/after the org's
                    // tracking start and the employee's hire date) with no approved leave
                    // and no clock-in — whether HR explicitly marked it 'absent' OR the
                    // record is simply missing. Mirrors the roster and payroll, which both
                    // treat an untracked past workday as an absence.
                    $absentStreak++;
                    $lateStreak = 0;

                    if ($absentStreak >= 3) {
                        $computed[] = $this->incident($emp->id, 'awol', 'red', $dateStr, "AWOL — {$absentStreak} consecutive days without notice");
                    } else {
                        $sev = $absentStreak >= 2 ? 'orange' : 'yellow';
                        $note = 'Unauthorised absence' . ($absentStreak >= 2 ? " ({$absentStreak} consecutive days)" : '');
                        $computed[] = $this->incident($emp->id, 'ua', $sev, $dateStr, $note);
                    }
                } else {
                    // Present / on-time / on approved leave — clears the streaks.
                    $lateStreak = 0;
                    $absentStreak = 0;

                    if ($row && $row->time_in && ! $row->time_out && $isPast) {
                        $computed[] = $this->incident($emp->id, 'missed_time_out', 'green', $dateStr, 'Clocked in but never clocked out');
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
     *
     * Same reason as recompute() for ignoring system-generated rows: they must not decide
     * how far back the history sweep reaches.
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
