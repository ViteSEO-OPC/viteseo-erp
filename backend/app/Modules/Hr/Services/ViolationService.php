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

    public function list(?int $employeeId = null): Collection
    {
        return Violation::with('employee.user')
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderByDesc('resolved') // unresolved first
            ->orderByDesc('work_date')
            ->get();
    }

    public function resolve(Violation $violation): Violation
    {
        $violation->update(['resolved' => true]);

        return $violation;
    }

    /**
     * Worst unresolved flag + count per employee, for the roster.
     *
     * @return array<int, array{severity:string, count:int, types:array<int,string>}>
     */
    public function flagsByEmployee(): array
    {
        return Violation::where('resolved', false)
            ->get()
            ->groupBy('employee_id')
            ->map(function ($group) {
                $worst = $group->sortByDesc(fn ($v) => Violation::SEVERITY_RANK[$v->severity] ?? 0)->first();

                return [
                    'severity' => $worst->severity,
                    'count' => $group->count(),
                    'types' => $group->pluck('type')->unique()->values()->all(),
                ];
            })
            ->all();
    }

    /** Regenerate violations for the current month across all employees. */
    public function recompute(): void
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->startOfDay();
        $monthStart = $today->copy()->startOfMonth();
        $latesBeforeAbsence = $this->policy->get()->consecutive_lates_before_absence;

        // Approved leaves overlapping this month, grouped by employee.
        $leaves = LeaveRequest::where('status', 'approved')
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->whereDate('start_date', '<=', $today->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        $computed = [];

        foreach (Employee::with('workSchedule')->get() as $emp) {
            $rows = Attendance::where('employee_id', $emp->id)
                ->whereBetween('work_date', [$monthStart->toDateString(), $today->toDateString()])
                ->get()
                ->keyBy(fn ($r) => $r->work_date->toDateString());

            $lateStreak = 0;
            $absentStreak = 0;
            $monthlyLate = 0;

            for ($d = $monthStart->copy(); $d->lte($today); $d->addDay()) {
                $eff = $this->schedule->effectiveFor($emp, $d);
                if (! $eff['is_workday']) {
                    continue; // rest day — never a violation
                }

                $dateStr = $d->toDateString();
                $row = $rows->get($dateStr);
                $isPast = $d->lt($today);
                $onLeave = $this->onLeave($leaves->get($emp->id), $dateStr);

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
                } elseif ($isPast && ! $onLeave && $row && $row->status === 'absent') {
                    // Only an EXPLICIT absent row is an unauthorised absence — a
                    // missing historical row is "untracked", not proof of absence.
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

        $this->sync($computed);
    }

    // --- helpers ---

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
     * never touches `resolved`, so an HR-resolved row stays resolved.
     *
     * @param  array<int, array<string, mixed>>  $computed
     */
    private function sync(array $computed): void
    {
        $keys = [];
        foreach ($computed as $c) {
            Violation::updateOrCreate(
                ['employee_id' => $c['employee_id'], 'type' => $c['type'], 'work_date' => $c['work_date']],
                ['severity' => $c['severity'], 'details' => $c['details']],
            );
            $keys[] = "{$c['employee_id']}|{$c['type']}|{$c['work_date']}";
        }

        Violation::where('resolved', false)->get()->each(function (Violation $v) use ($keys) {
            $key = "{$v->employee_id}|{$v->type}|" . $v->work_date->toDateString();
            if (! in_array($key, $keys, true)) {
                $v->delete();
            }
        });
    }
}
