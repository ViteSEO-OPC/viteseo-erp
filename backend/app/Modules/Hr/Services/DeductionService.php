<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendancePolicy;

/**
 * Prices ONE worked day's late + undertime shortfall into a fraction of a day
 * (audit §7 step 4). PURE: no DB, no queries, no clock — every input is
 * passed in, so it is unit-testable without building a payroll run.
 */
class DeductionService
{
    /**
     * @param  array<string,mixed>  $ctx  the day's facts (all times as Manila minute-of-day ints):
     *   - start_min, end_min: int|null           schedule window
     *   - grace_min: int                          resolved grace (via effectiveFor → daily overrides)
     *   - time_in_min: int|null                   clock-in (null ⇒ not a worked day; returns zero)
     *   - time_out_min: int|null                  clock-out (null ⇒ missing punch; see missing_clockout_mode)
     *   - half_day_off: 'AM'|'PM'|null            which half is off (worked window is the OTHER half)
     *   - am_start_min, am_end_min, pm_start_min, pm_end_min: int|null   half windows
     *   - unpaid_break_minutes: int               the schedule's unpaid meal; caps what the gap
     *                                             between the halves may excuse (0 ⇒ no meal known,
     *                                             so nothing is excused)
     *   - governed_by_early_out: bool             an approved early-out already prices this day's undertime
     *   - amnesty: bool                           force-majeure waiver for this date (§9) ⇒ zero
     *   - full_day_credited: bool                 the day cleared HR's minimum-hours bar ⇒ zero undertime
     *   - cap_remaining: float                    max fraction this day may still cost (1.0 full day; 0.5 left on a half-day)
     */
    public function forDay(array $ctx, AttendancePolicy $policy): DeductionResult
    {
        $timeIn = $ctx['time_in_min'] ?? null;
        if ($timeIn === null) {
            return DeductionResult::zero(); // absent / no clock-in — priced by unpaidDaysFor, not here
        }

        // Force-majeure amnesty (typhoon, transit strike) zeroes the day's deduction (§9).
        if (! empty($ctx['amnesty'])) {
            return DeductionResult::zero();
        }

        // Break/lunch overage is never charged, and is no longer detected here: ViolationService
        // owns it, so it is flagged against the employee's own schedule entitlement and
        // works whether or not a deduction mode is switched on. Breaks are not a pay input.
        $flags = [];

        // --- the worked window (a half-day shifts start/end to the worked half) ---
        [$workStart, $workEnd] = $this->workedWindow($ctx);
        $timeOut = $ctx['time_out_min'] ?? null;

        // Item 15 — an OVERNIGHT shift (end before start, e.g. 22:00–06:00) needs the end and
        // any post-midnight punches rolled past 24h before these raw minute-of-day values can be
        // compared. Without it a 00:30 arrival computed max(0, 30 − 1320) = 0 late, and a 23:00
        // departure looked like no undertime at all — a graveyard worker could never be charged.
        $overnight = $workStart !== null && $workEnd !== null && $workEnd < $workStart;
        if ($overnight) {
            $boundary = intdiv($workEnd + $workStart, 2);
            $roll = static fn (?int $t): ?int => $t !== null && $t <= $boundary ? $t + 1440 : $t;

            $timeIn = $roll($timeIn);
            $timeOut = $roll($timeOut);
            $workEnd += 1440;
        }

        // --- late ---
        $lateMode = $policy->late_deduction_mode;
        $lateMinutes = 0;
        $effectiveStart = null;
        if ($workStart !== null && ($lateMode === 'per_hour' || $lateMode === 'tiered')) {
            $effectiveStart = $policy->late_minutes_from === 'after_grace'
                ? $workStart + (int) ($ctx['grace_min'] ?? 0)
                : $workStart;
            $lateMinutes = max(0, $timeIn - $effectiveStart);
        }

        // --- undertime (clock-derived; approved early-out days are handled elsewhere) ---
        $undertimeMinutes = 0;
        if (
            $policy->undertime_deduction_mode === 'per_hour'
            && empty($ctx['governed_by_early_out'])
            && empty($ctx['full_day_credited'])
            && $workEnd !== null
            && $timeOut !== null // a missing clock-out is flagged, not charged (default mode)
        ) {
            $undertimeMinutes = max(0, $workEnd - $timeOut);
        }
        if ($timeOut === null) {
            $flags[] = 'missing_clockout';
        }

        // --- the unpaid meal is never charged as missed time ---
        // A shortfall SPAN can straddle the meal. Leaving at 12:30 on an 08:00–18:00 shift is 330
        // clock minutes short of the end but only 270 minutes of work the company actually lost,
        // and arriving at 14:00 is 360 minutes late over the same 300 minutes of missed work.
        // Charging the span whole billed the meal hour as though it had been due to be worked —
        // an hour the employee is not paid for either way, taken off them twice.
        //
        // The REPORTED minutes stay raw: they answer "how much of my shift did I miss", which is
        // what the employee's badge and the violation engine measure. Only the money is netted.
        $mealStart = $ctx['am_end_min'] ?? null;
        $mealEnd = $ctx['pm_start_min'] ?? null;
        $mealCap = max(0, (int) ($ctx['unpaid_break_minutes'] ?? 0));
        // Skipped outright on an overnight shift: the meal window's own minute-of-day values would
        // have to be rolled too, and which side of midnight they belong to is not something the
        // roll above can decide for a gap it never saw a punch inside. Under-charging by an hour
        // is the safe direction to be wrong in.
        $mealWithin = function (?int $from, ?int $to) use ($mealStart, $mealEnd, $mealCap, $overnight): int {
            if ($overnight || $from === null || $to === null || $mealStart === null || $mealEnd === null || $mealEnd <= $mealStart) {
                return 0;
            }

            return min($mealCap, max(0, min($to, $mealEnd) - max($from, $mealStart)));
        };

        // --- convert charged minutes → billable minutes → fraction of a day ---
        $chargedLate = ($lateMode === 'tiered')
            // A ladder, not a span: the rung is chosen by how late they were, so there is no
            // stretch of clock inside it for the meal to be double-counted in.
            ? $this->tierMinutes($lateMinutes, $policy)
            : max(0, $lateMinutes - $mealWithin($effectiveStart, $timeIn));
        $chargedUndertime = max(0, $undertimeMinutes - $mealWithin($timeOut, $workEnd));

        // Item 9 — the audit spec rounds the shortfall ONCE. Rounding each component separately
        // over-charged under the round_* modes: 30 min late + 30 min undertime became two 60-min
        // blocks instead of one. Round the total, then split it back proportionally so the two
        // display figures still sum to what is actually charged.
        $rawShortfall = $chargedLate + $chargedUndertime;
        $totalFraction = $this->toFraction($rawShortfall, $policy);
        $lateFraction = $rawShortfall > 0 ? $totalFraction * ($chargedLate / $rawShortfall) : 0.0;
        $undertimeFraction = $totalFraction - $lateFraction;

        $capRemaining = (float) ($ctx['cap_remaining'] ?? (float) $policy->daily_deduction_cap);
        $capRemaining = max(0.0, min($capRemaining, (float) $policy->daily_deduction_cap));

        // Cap the TOTAL for the day, then split the (possibly reduced) fraction back across
        // the two components proportionally so late_deduction + undertime_deduction == total.
        $rawFraction = $lateFraction + $undertimeFraction;
        $unpaidFraction = min($capRemaining, $rawFraction);

        if ($rawFraction > 0 && $unpaidFraction < $rawFraction) {
            $scale = $unpaidFraction / $rawFraction;
            $lateFraction *= $scale;
            $undertimeFraction *= $scale;
        }

        return new DeductionResult(
            unpaidFraction: round($unpaidFraction, 6),
            lateMinutes: $lateMinutes,
            undertimeMinutes: $undertimeMinutes,
            lateFraction: round($lateFraction, 6),
            undertimeFraction: round($undertimeFraction, 6),
            flags: array_values(array_unique($flags)),
        );
    }

    /**
     * The window the employee was expected to work. Normally the full schedule; on a
     * half-day, the half they were NOT off.
     *
     * @param  array<string,mixed>  $ctx
     * @return array{0:int|null,1:int|null}  [start_min, end_min]
     */
    private function workedWindow(array $ctx): array
    {
        $off = $ctx['half_day_off'] ?? null;

        if ($off === 'AM') {
            return isset($ctx['pm_start_min'], $ctx['pm_end_min'])
                ? [$ctx['pm_start_min'], $ctx['pm_end_min']]
                : [null, null];
        }
        if ($off === 'PM') {
            return isset($ctx['am_start_min'], $ctx['am_end_min'])
                ? [$ctx['am_start_min'], $ctx['am_end_min']]
                : [null, null];
        }
        // 'BOTH' (or any unrecognised value): the whole day is off, so there is no worked
        // window and nothing to charge against one.
        if ($off !== null) {
            return [null, null];
        }

        return [$ctx['start_min'] ?? null, $ctx['end_min'] ?? null];
    }

    /** Charged minutes → fraction of a day, priced at the DOLE 8-hour day (dailyRate ÷ 8). */
    private function toFraction(int $chargedMinutes, AttendancePolicy $policy): float
    {
        if ($chargedMinutes <= 0) {
            return 0.0;
        }
        $billable = $this->applyRounding($chargedMinutes, $policy);
        $minutesPerDay = max(1, (int) $policy->hours_per_day_divisor * 60);

        return $billable / $minutesPerDay;
    }

    /**
     * Snap raw shortfall minutes to the rounding rule. `exact` (the legal default) charges
     * the precise time; the round_* modes snap to `deduction_block_minutes` and carry
     * employer liability (§9), so they are an explicit opt-in, never the default.
     */
    private function applyRounding(int $rawMinutes, AttendancePolicy $policy): int
    {
        $block = max(1, (int) $policy->deduction_block_minutes);

        return match ($policy->deduction_rounding) {
            'round_up' => (int) (ceil($rawMinutes / $block) * $block),
            'round_down' => (int) (floor($rawMinutes / $block) * $block),
            'round_nearest' => (int) (round($rawMinutes / $block) * $block),
            default => $rawMinutes, // 'exact'
        };
    }

    /**
     * `tiered` late mode: the highest ladder rung whose `over` threshold the lateness
     * exceeds decides the charged HOURS (converted to minutes). No rung matched ⇒ 0.
     */
    private function tierMinutes(int $lateMinutes, AttendancePolicy $policy): int
    {
        $tiers = $policy->late_tiers ?? [];
        $hours = 0.0;
        foreach ($tiers as $tier) {
            if ($lateMinutes > (int) ($tier['over'] ?? PHP_INT_MAX)) {
                $hours = max($hours, (float) ($tier['hours'] ?? 0));
            }
        }

        return (int) round($hours * 60);
    }

}
