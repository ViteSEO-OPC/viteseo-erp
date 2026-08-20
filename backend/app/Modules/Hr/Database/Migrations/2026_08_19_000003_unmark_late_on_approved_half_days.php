<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clear the `late` marks left on days an employee had a HALF DAY approved.
 *
 * Until now only DeductionService knew that an approved half day shrinks the working window.
 * The clock-in path measured every arrival against the full shift, so somebody with the morning
 * off who walked in at 1:00 PM was stamped `late` by five hours. That status is not cosmetic: it
 * raises a disciplinary violation, it inflates the roster's "Late arrivals" tile, and — with
 * `late_penalty_enabled` — the per-N-lates rule counts it, so three of them cost a full day's pay.
 *
 * The service fix corrects every row written from here on. This corrects the ones already stored,
 * because nothing downstream re-derives the status: payroll, the violation ladder and the reports
 * all read the column.
 *
 * ONLY EVER `late` → `present`, NEVER THE REVERSE. Narrowing a day to the half actually worked can
 * only move the cutoff LATER (the afternoon half starts after the morning does), so no row that is
 * currently `present` could become late under the new rule. That makes this safe to run blind: the
 * worst a mistake here can do is leave a wrong `late` standing, never invent one.
 *
 * ROWS HR TOUCHED BY HAND ARE LEFT ALONE (`status_override`). A human asserting a status outranks
 * any derivation, which is the whole point of that column — including the HR officers who have
 * been using it to work around this very bug.
 *
 * Raw queries, no services: a migration has to keep working when the classes it was written
 * against have moved on. The resolution below mirrors ScheduleService::effectiveFor() and
 * HalfDayWindowService::windowFor() as they stand today — employee override → assigned schedule →
 * default schedule, with the AM/PM windows coming from the schedule template only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $offHalves = $this->offHalves();
        if ($offHalves === []) {
            return; // no half days have ever been approved — nothing to correct
        }

        $default = DB::table('hr_work_schedules')->orderByDesc('is_default')->orderBy('id')->first();
        $graceOverrides = DB::table('hr_daily_overrides')->pluck('grace_minutes', 'date');
        $tz = config('app.timezone_local');
        $cleared = [];

        foreach ($offHalves as $key => $offHalf) {
            [$employeeId, $date] = explode('|', $key);

            $row = DB::table('hr_attendance')
                ->where('employee_id', $employeeId)
                ->where('work_date', $date)
                ->whereNull('deleted_at')
                ->whereNotNull('time_in')
                ->where('status', 'late')
                ->where(fn ($q) => $q->where('status_override', false)->orWhereNull('status_override'))
                ->first();

            if (! $row) {
                continue;
            }

            $employee = DB::table('hr_employees')->where('id', $employeeId)->first();
            if (! $employee) {
                continue;
            }

            $schedule = $employee->work_schedule_id
                ? DB::table('hr_work_schedules')->where('id', $employee->work_schedule_id)->first()
                : $default;

            $start = $this->expectedStart($employee, $schedule, $offHalf);
            $grace = (int) ($graceOverrides[$date]
                ?? $employee->custom_grace_minutes
                ?? $schedule?->grace_minutes
                ?? 0);

            // BOTH halves off: there is no shift left to have been late for.
            $onTime = $start === null
                || Carbon::parse($row->time_in, 'UTC')->setTimezone($tz)
                    ->lte(Carbon::parse("{$date} {$start}", $tz)->addMinutes($grace));

            if (! $onTime) {
                continue; // genuinely late for the half they were expected to work
            }

            DB::table('hr_attendance')->where('id', $row->id)->update(['status' => 'present']);
            $cleared[] = [$employeeId, $date];
        }

        // The disciplinary incidents those statuses produced. Deleted rather than corrected
        // because ViolationService rebuilds a month from the attendance rows whenever it syncs,
        // so an incident that should never have existed simply does not come back. RESOLVED ones
        // stay: HR actioned them, and erasing that conversation is not this migration's business.
        foreach ($cleared as [$employeeId, $date]) {
            DB::table('hr_violations')
                ->where('employee_id', $employeeId)
                ->where('type', 'late')
                ->where('work_date', $date)
                ->where('resolved', false)
                ->delete();
        }
    }

    /**
     * "employeeId|Y-m-d" => 'AM' | 'PM' | 'BOTH', from both sources of a half day.
     *
     * @return array<string, string>
     */
    private function offHalves(): array
    {
        /** @var array<string, array<string, true>> $halves */
        $halves = [];

        foreach (DB::table('hr_half_day_requests')->where('status', 'approved')->whereNull('deleted_at')->get() as $r) {
            $date = Carbon::parse($r->work_date)->toDateString();
            $halves["{$r->employee_id}|{$date}"][strtoupper((string) $r->period)] = true;
        }

        foreach (
            DB::table('hr_leave_requests')
                ->where('status', 'approved')
                ->where('is_half_day', true)
                ->whereNotNull('half_day_period')
                ->whereNull('deleted_at')
                ->get() as $r
        ) {
            $period = strtoupper((string) $r->half_day_period);
            for (
                $d = Carbon::parse($r->start_date);
                $d->toDateString() <= Carbon::parse($r->end_date)->toDateString();
                $d->addDay()
            ) {
                $halves["{$r->employee_id}|{$d->toDateString()}"][$period] = true;
            }
        }

        $out = [];
        foreach ($halves as $key => $periods) {
            $out[$key] = count($periods) > 1 ? 'BOTH' : (string) array_key_first($periods);
        }

        return $out;
    }

    /** 'HH:MM' the employee was actually due in, or null when the whole day was off. */
    private function expectedStart(object $employee, ?object $schedule, string $offHalf): ?string
    {
        if ($offHalf === 'BOTH') {
            return null;
        }

        $hm = fn (?string $t): ?string => $t ? substr($t, 0, 5) : null;

        // Half-day windows are template-only, exactly as effectiveFor() reads them.
        if ($offHalf === 'AM' && $hm($schedule?->pm_start_time) !== null) {
            return $hm($schedule->pm_start_time);
        }
        if ($offHalf === 'PM' && $hm($schedule?->am_start_time) !== null) {
            return $hm($schedule->am_start_time);
        }

        // No half windows configured: the day keeps its full shift, so anything still marked
        // late was late by the same rule the new code applies. Returning the full start leaves
        // those rows untouched.
        return $hm($employee->custom_start_time ?? $schedule?->start_time) ?? '09:00';
    }

    public function down(): void
    {
        // Irreversible by design: the `late` marks this removes were wrong, and there is no
        // record of which rows carried them that would not itself be the bug.
    }
};
