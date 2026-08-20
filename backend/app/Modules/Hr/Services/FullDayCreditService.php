<?php

namespace App\Modules\Hr\Services;

/**
 * Decides whether ONE day cleared the "minimum hours = full day" threshold that HR declared for
 * that date (hr_daily_overrides.min_full_day_minutes).
 *
 * PURE: no DB, no queries, no clock — every input is passed in, so it is unit-testable without
 * building a payroll run. Same contract as DeductionService, and for the same reason.
 *
 * WHY IT EXISTS. A DOLE advisory (or the company) can shorten the compensable day: "six hours
 * of work is a full day today". An employee on an 8:00–18:00 schedule who leaves at 15:00 has
 * worked six hours once the unpaid meal is taken out, so the day is WHOLE — no undertime, no
 * early-out charge, no half-day charge — even though they left three hours before their
 * scheduled end.
 *
 * THE ONE INVARIANT: this service can only ever REMOVE a deduction, never add one. Callers must
 * treat `met() === false` as "nothing changes", not as "charge them". That is what keeps the
 * feature safe for the people it would otherwise hurt most — a part-timer on a four-hour
 * schedule, or somebody with an approved half-day, can never clear a six-hour bar, and must not
 * be docked for failing to. They are priced exactly as they were before this existed.
 *
 * WHAT COUNTS AS WORKED (all three rules are deliberate):
 *   1. Time OUTSIDE the scheduled window does not count, so the bar cannot be cleared by
 *      arriving two hours early or staying two hours late. Unauthorised time is not credited
 *      here for the same reason it is not paid anywhere else in the module.
 *   2. UNLESS overtime was approved for that date, in which case time past the scheduled end
 *      does count — HR authorised it, so it is real work. This is not a nicety: an employee
 *      whose afternoon is off finishes at 18:00 having worked five hours, and approved OT is
 *      the only honest way for them to reach a six-hour bar at all.
 *   3. The unpaid meal comes out, but only the part of it the employee was actually still
 *      present for. Somebody who leaves at 11:00 never took lunch and must not be charged for
 *      one; somebody who leaves at 15:00 did, and must be.
 *
 * The meal is measured against the SCHEDULE (the gap between am_end_time and pm_start_time),
 * not against the break button the employee taps. Self-reported minutes decide pay nowhere else
 * in this module, and a threshold that could be cleared by forgetting to press "lunch" is not a
 * threshold. The tapped figure is used only when the schedule has no half-day windows to derive
 * a meal from, which is the one case where there is nothing better to fall back on.
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
     * Null (not zero) for a missing punch, because the two mean different things and only one of
     * them is safe: zero would read as "worked nothing, so below the bar", which is the correct
     * answer here only because failing the bar costs nothing. Keeping them distinct stops the
     * next caller from inheriting that accident.
     *
     * A missing clock-out is NOT credited to the scheduled end the way pay treats it (item 6).
     * Auto-closing an abandoned punch is a defensible way to avoid charging somebody for a
     * forgotten button; using that same invented time to hand them a full day's pay is not.
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
        //
        // The window is the FULL schedule even on a half-day. That is the whole point of the
        // employee's escape hatch: somebody whose afternoon is approved off may simply work the
        // afternoon anyway to reach the bar, and every minute of it is inside their normal
        // schedule, so all of it counts. Clamping to the half they were owed would make that
        // impossible and leave them working for free.
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
     * This is the number the employee actually needs: "six hours today" is a rule, but "you
     * reach a full day at 3:12 PM" is an instruction. Announcing the rule without it leaves
     * every employee doing meal-window arithmetic in their head, and getting it wrong costs
     * them pay.
     *
     * Scans rather than solving algebraically: worked-minutes is monotonic in the clock-out but
     * flat across the meal (sitting through lunch earns nothing), so the closed form is a stack
     * of cases that would have to be kept in step with workedMinutes() by hand. Asking the same
     * method the payslip uses, once per minute of one day, cannot drift from it — and this runs
     * once per page load, not once per employee-day of a payroll run.
     *
     * OVERNIGHT SHIFTS RETURN NULL. The scan walks raw minute-of-day values, which the
     * cross-midnight roll in workedMinutes() would reinterpret; rather than quietly print a
     * wrong time, the panel simply shows the rule without the hint.
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
     * The OVERLAP, not the whole meal: leaving at 12:30 on a 12:00–13:00 lunch costs thirty
     * minutes, not sixty. Capped at the schedule's unpaid_break_minutes so a wide am_end→pm_start
     * gap cannot deduct more meal than the schedule says is unpaid.
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
