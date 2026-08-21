<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\JobRun;
use App\Modules\Hr\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** Generates the attendance rows for management staff. */
class ManagementAttendanceService
{
    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly HalfDayWindowService $halfDays,
        private readonly ViolationService $violations,
        private readonly AttendancePolicyService $policy,
    ) {}

    /**
     * The whole job: resolve the Manila range, generate, then refresh the violations those days
     * feed. Shared by the nightly command and HR's regenerate button so the two cannot drift.
     *
     * @param  ?string  $from  first date, or null for `$days` back from `$to`
     * @param  ?string  $to  last date, or null for today (never later — see below)
     * @return array{from:string, to:string, recomputed:bool, stats:array{created:int, updated:int, skipped:int, employees:int, notes:array<int, string>}}
     */
    public function run(?string $from = null, ?string $to = null, int $days = 7, bool $dryRun = false, bool $recompute = true): array
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->startOfDay();

        $end = $to ? Carbon::parse($to, $tz)->startOfDay() : $today->copy();

        // Never invent presence for a day that has not happened yet.
        if ($end->gt($today)) {
            $end = $today->copy();
        }

        $begin = $from
            ? Carbon::parse($from, $tz)->startOfDay()
            : $end->copy()->subDays(max(1, $days) - 1);

        $stats = $this->generate($begin->toDateString(), $end->toDateString(), $dryRun);

        // New rows clear the bogus absences those days used to produce, but the stored
        // violations only change when they are recomputed.
        $written = $stats['created'] + $stats['updated'];
        $recomputed = ! $dryRun && $recompute && $written > 0;

        if ($recomputed) {
            for ($m = $begin->copy()->startOfMonth(); $m->lte($end); $m->addMonth()) {
                $this->violations->recompute($m->format('Y-m'));
            }
        }

        return [
            'from' => $begin->toDateString(),
            'to' => $end->toDateString(),
            'recomputed' => $recomputed,
            'stats' => $stats,
        ];
    }

    /**
     * What HR needs to know about the nightly: when it last ran, what it wrote, and whether it
     * looks dead. The job needs `php artisan schedule:run` on a system cron, and a dead cron is
     * otherwise indistinguishable from a quiet week.
     *
     * @return array{employees:int, is_stale:bool, last_run:?array<string, mixed>}
     */
    public function status(): array
    {
        $last = JobRun::with('trigger')
            ->where('job', JobRun::MANAGEMENT_ATTENDANCE)
            ->latest('id')
            ->first();

        return [
            'employees' => Employee::where('is_management', true)->count(),
            // The company rule. Off, there is no nightly to be stale about.
            'enabled' => (bool) $this->policy->get()->management_auto_attendance,
            // One missed nightly, not a blip: the job runs daily at 23:55, so nothing in 36
            // hours means it did not run last night either.
            'is_stale' => $last === null || $last->created_at->lt(now()->subHours(36)),
            'last_run' => $last === null ? null : [
                'ran_at' => $last->created_at,
                'range_start' => $last->range_start?->toDateString(),
                'range_end' => $last->range_end?->toDateString(),
                'created' => (int) ($last->stats['created'] ?? 0),
                'updated' => (int) ($last->stats['updated'] ?? 0),
                'skipped' => (int) ($last->stats['skipped'] ?? 0),
                'notes' => $last->stats['notes'] ?? [],
                // Null = the scheduler; a name = the person who pressed regenerate.
                'triggered_by' => $last->trigger?->name,
            ],
        ];
    }

    /**
     * Generate/refresh management rows across an inclusive Manila date range.
     *
     * @return array{created:int, updated:int, skipped:int, employees:int, notes:array<int, string>}
     */
    public function generate(string $from, string $to, bool $dryRun = false): array
    {
        $tz = config('app.timezone_local');
        $start = Carbon::parse($from, $tz)->startOfDay();
        $end = Carbon::parse($to, $tz)->startOfDay();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'employees' => 0, 'notes' => []];

        if ($end->lt($start)) {
            $stats['notes'][] = "Empty range: {$from} is after {$to}.";

            return $stats;
        }

        // The company rule (Company → Rules). Off, management is on the time clock like
        // everyone else and there is nothing for this job to write.
        $managementAuto = (bool) $this->policy->get()->management_auto_attendance;

        if (! $managementAuto) {
            $stats['notes'][] = 'Auto time in/out for management is switched off in Company → Rules — nothing generated.';

            return $this->record($from, $to, $stats, $dryRun);
        }

        // withTrashed: a separation soft-deletes the employee the MOMENT HR files it, stamping
        // deleted_at with the last working day — which may still be days away. Without this the
        // notice period silently stops generating, and someone who is still coming to work reads
        // as absent. The per-day employment window below is what actually ends the series.
        $employees = Employee::withTrashed()->with('workSchedule')->where('is_management', true)->get();
        $stats['employees'] = $employees->count();

        if ($employees->isEmpty()) {
            return $this->record($from, $to, $stats, $dryRun);
        }

        // Approved leave overlapping the range, grouped by employee — one query, not one per day.
        // HALF-day leave is excluded here and handled through HalfDayWindowService below: it
        // excuses one half, not the day, so the other half is still a working half.
        $leaves = LeaveRequest::where('status', 'approved')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('end_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->toDateString())
            ->where(fn ($q) => $q->where('is_half_day', false)->orWhereNull('half_day_period'))
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        // Approved half days (leave AND half-day requests) for the whole range, in one lookup.
        $offHalves = $this->halfDays->offHalvesBetween($start->toDateString(), $end->toDateString());

        foreach ($employees as $emp) {
            $hireDate = $emp->hire_date?->toDateString();
            // Last day the company expected them in. separation_date is the field of record;
            // deleted_at is the fallback for a row archived without one.
            $lastDay = $emp->separation_date?->toDateString()
                ?? ($emp->trashed() ? $emp->deleted_at?->toDateString() : null);
            $empLeaves = $leaves->get($emp->id);

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dateStr = $d->toDateString();

                // Outside employment entirely — before they were hired, or after their last day.
                if (($hireDate && $dateStr < $hireDate) || ($lastDay && $dateStr > $lastDay)) {
                    $stats['skipped']++;

                    continue;
                }

                // Before the exemption began. The nightly reaches a week back so a missed run
                // self-heals, and that window can easily span a promotion — writing 'present'
                // into those days would fabricate attendance for days the employee was still on
                // the clock, quietly covering a real absence.
                if (! $emp->managementExemptOn($dateStr, $managementAuto)) {
                    $stats['skipped']++;

                    continue;
                }

                $eff = $this->schedule->effectiveFor($emp, $d);
                $offHalf = $offHalves["{$emp->id}|{$dateStr}"] ?? null;

                // BOTH halves filed off is a whole day off, reached by two requests instead of one.
                if (! $eff['is_workday']
                    || $offHalf === HalfDayWindowService::BOTH
                    || $this->onLeave($empLeaves, $dateStr)) {
                    $stats['skipped']++;

                    continue;
                }

                // The half they were actually expected for — the full shift on an ordinary day.
                [$startTime, $endTime] = $this->halfDays->windowFor($eff, $offHalf);

                if ($startTime === null || $endTime === null) {
                    $stats['skipped']++;
                    $stats['notes'][] = "Employee #{$emp->id} has no usable shift window on {$dateStr} — skipped.";

                    continue;
                }

                // withTrashed: a soft-deleted row still holds the (employee, work_date)
                // unique key, so inserting over it would fail. A deleted row is also a
                // deliberate act — leave it deleted.
                $existing = Attendance::withTrashed()
                    ->where('employee_id', $emp->id)
                    ->where('work_date', $dateStr)
                    ->first();

                if ($existing && ($existing->trashed() || ! $existing->isSystemGenerated())) {
                    $stats['skipped']++;

                    continue;
                }

                $row = $existing ?? new Attendance([
                    'employee_id' => $emp->id,
                    'work_date' => $dateStr,
                ]);

                $this->fill($row, $d, $eff, $startTime, $endTime, $offHalf);

                if (! $dryRun) {
                    $row->save();
                }

                $stats[$existing ? 'updated' : 'created']++;
            }
        }

        return $this->record($from, $to, $stats, $dryRun);
    }

    /**
     * Stamp that this run happened. The generator only runs from a system cron, so its silence
     * and its success look identical from the HR side — this is what makes a dead cron visible.
     * A dry run reports without claiming anything was written.
     *
     * @param  array{created:int, updated:int, skipped:int, employees:int, notes:array<int, string>}  $stats
     * @return array{created:int, updated:int, skipped:int, employees:int, notes:array<int, string>}
     */
    private function record(string $from, string $to, array $stats, bool $dryRun): array
    {
        if ($dryRun) {
            return $stats;
        }

        JobRun::create([
            'job' => JobRun::MANAGEMENT_ATTENDANCE,
            'range_start' => $from,
            'range_end' => $to,
            'stats' => $stats,
            // Null when the scheduler ran it; a user id when someone pressed the button.
            'triggered_by' => Auth::id(),
        ]);

        return $stats;
    }

    /**
     * Populate a row from the window the employee was expected to work that day.
     *
     * @param  array<string, mixed>  $eff
     * @param  ?string  $offHalf  a HalfDayWindowService::AM|PM value, or null for a full day
     */
    private function fill(Attendance $row, Carbon $localDay, array $eff, string $startTime, string $endTime, ?string $offHalf): void
    {
        $tz = config('app.timezone_local');

        $timeIn = Carbon::parse("{$localDay->toDateString()} {$startTime}", $tz);
        $timeOut = Carbon::parse("{$localDay->toDateString()} {$endTime}", $tz);
        if ($timeOut->lt($timeIn)) {
            $timeOut->addDay(); // overnight schedule ends on the following morning
        }

        // The unpaid meal break sits BETWEEN the two halves, so a half-day window never
        // contains it — deducting it there would shorten a half day by an hour it never had.
        $lunch = $offHalf === null ? (int) $eff['unpaid_break_minutes'] : 0;
        $paidMinutes = max(0, ($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 60 - $lunch);

        $row->time_in = $timeIn->utc();
        $row->time_out = $timeOut->utc();
        $row->lunch_minutes = $lunch;
        $row->break_minutes = 0;
        $row->hours_worked = round($paidMinutes / 60, 2);
        // Management is exempt from the clock, so they are never 'late' — the arrival
        // category is meaningless for a shift that was never actually scanned. A half day
        // stays 'present' too: they worked the half they were expected for, and the half
        // they were excused from is a fact of the REQUEST, not of this row (the leave
        // credit stays charged, exactly as it does for everyone else).
        $row->status = 'present';
        $row->source = Attendance::SOURCE_SYSTEM;
    }

    /**
     * Does an approved full-day leave cover this date?
     *
     * @param  Collection<int, LeaveRequest>|null  $intervals
     */
    private function onLeave($intervals, string $date): bool
    {
        if (! $intervals) {
            return false;
        }

        foreach ($intervals as $leave) {
            if ($leave->start_date->toDateString() <= $date && $leave->end_date->toDateString() >= $date) {
                return true;
            }
        }

        return false;
    }
}
