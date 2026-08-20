<?php

namespace App\Modules\Hr\Services;

/**
 * Decides whether ONE day cleared the "minimum hours = full day" threshold that HR declared for
 * that date (hr_daily_overrides.min_full_day_minutes).
 */
class FullDayCreditService
{
    /**
     * @param  array<string,mixed>  $ctx  the day's facts (times as Manila minute-of-day ints):
     *   - threshold_min: int|null            the day's declared bar; null ⇒ no threshold, never met
     *   - start_min, end_min: int|null       the FULL schedule window (not the half-day's half —
     *                                        see workedMinutes() for why)
     *   - time_in_min, time_out_min: int|null  the punches; either missing ⇒ never met
     *   - am_end_min, pm_start_min: int|null the meal window (its gap IS the unpaid meal)
     *   - unpaid_break_minutes: int          caps the meal deduction at what the schedule says
     *   - lunch_minutes: int                 tapped lunch; fallback only
     *   - overtime_approved: bool            credit time past the scheduled end
     */
    public function met(array $ctx): bool
    {
        $threshold = $ctx['threshold_min'] ?? null;

        if ($threshold === null || $threshold <= 0) {
            return false; // no rule today — the overwhelmingly common case
        }

        $worked = $this->workedMinutes($ctx);

        return $worked !== null && $worked >= $threshold;
    }

    /**
     * Credited worked minutes for the day, or null when the day cannot be judged at all.
     *
     * @param  array<string,mixed>  $ctx
     */
    public function workedMinutes(array $ctx): ?int
    {
        $in = $ctx['time_in_min'] ?? null;
        $out = $ctx['time_out_min'] ?? null;

        if ($in === null || $out === null) {
            return null;
        }

        $start = $ctx['start_min'] ?? null;
        $end = $ctx['end_min'] ?? null;
        $mealStart = $ctx['am_end_min'] ?? null;
        $mealEnd = $ctx['pm_start_min'] ?? null;

        // Item 15 — an OVERNIGHT shift (end before start, e.g. 22:00–06:00) has to have its end
        // and any post-midnight punch rolled past 24h before these raw minute-of-day values can
        // be compared, exactly as DeductionService does it. Which side of midnight a punch
        // belongs to is decided by the midpoint of the off-shift gap.
        if ($start !== null && $end !== null && $end < $start) {
            $boundary = intdiv($end + $start, 2);
            $roll = static fn (?int $t): ?int => $t !== null && $t <= $boundary ? $t + 1440 : $t;

            $in = $roll($in);
            $out = $roll($out);
            $mealStart = $roll($mealStart);
            $mealEnd = $roll($mealEnd);
            $end += 1440;
        }

        // --- clamp to the authorised window -------------------------------------------------
        if ($start !== null) {
            $in = max($in, $start);
        }
        if ($end !== null && empty($ctx['overtime_approved'])) {
            $out = min($out, $end);
        }

        $worked = max(0, $out - $in);

        return max(0, $worked - $this->mealMinutes($in, $out, $mealStart, $mealEnd, $ctx));
    }

    /**
     * The earliest clock-out that would reach the threshold, as a Manila minute-of-day, or null
     * when it cannot be reached at all (or there is nothing to reach).
     *
     * @param  array<string,mixed>  $ctx  as met(), with `time_out_min` ignored
     */
    public function reachesAt(array $ctx): ?int
    {
        $threshold = $ctx['threshold_min'] ?? null;
        $in = $ctx['time_in_min'] ?? null;
        $start = $ctx['start_min'] ?? null;
        $end = $ctx['end_min'] ?? null;

        if ($threshold === null || $threshold <= 0 || $in === null) {
            return null;
        }
        if ($start !== null && $end !== null && $end < $start) {
            return null; // overnight — see the note above
        }

        // Nobody may credit time past their scheduled end without approved overtime, so that is
        // where the search stops; with OT approved there is no such ceiling, and a full day is
        // capped only by the day itself.
        $ceiling = (! empty($ctx['overtime_approved']) || $end === null) ? $in + 1440 : $end;

        for ($out = max($in, $start ?? $in); $out <= $ceiling; $out++) {
            if (($this->workedMinutes(['time_out_min' => $out] + $ctx) ?? 0) >= $threshold) {
                return $out;
            }
        }

        return null;
    }

    // --- helpers ---

    /**
     * How much of the unpaid meal this employee actually sat through.
     *
     * @param  array<string,mixed>  $ctx
     */
    private function mealMinutes(int $in, int $out, ?int $mealStart, ?int $mealEnd, array $ctx): int
    {
        $cap = max(0, (int) ($ctx['unpaid_break_minutes'] ?? 0));

        // No derivable meal window. Fall back to what the employee tapped — worse, but the only
        // figure available, and still capped so it cannot exceed the schedule's unpaid meal.
        if ($mealStart === null || $mealEnd === null || $mealEnd <= $mealStart) {
            return min($cap, max(0, (int) ($ctx['lunch_minutes'] ?? 0)));
        }

        $overlap = min($out, $mealEnd) - max($in, $mealStart);

        return min($cap, max(0, $overlap));
    }
}
