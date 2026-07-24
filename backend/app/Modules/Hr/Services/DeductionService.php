<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendancePolicy;

/**
 * Prices ONE worked day's late + undertime shortfall into a fraction of a day
 * (Slice 2, audit §7 step 4). PURE: no DB, no queries, no clock — every input is
 * passed in, so it is unit-testable without building a payroll run.
 *
 * The model (audit §7, revised 2026-07-23):
 *   lateMinutes      = max(0, time_in − effectiveStart)      ← effectiveStart per late_minutes_from
 *   undertimeMinutes = max(0, workedEnd − time_out)          ← workedEnd is the half's end on a half-day
 *   rawShortfall     = (charged late) + (charged undertime)  ← breaks & lunch NEVER contribute
 *   billable         = applyRounding(rawShortfall)           ← exact by default (PH Art. 113, §9)
 *   unpaidFraction   = min(capRemaining, billable ÷ (hoursPerDay × 60))   ← hourly = dailyRate ÷ 8 (DOLE)
 *
 * NO-OP GUARANTEE: with the seeded defaults (late_deduction_mode=none,
 * undertime_deduction_mode=none) this returns DeductionResult::zero() for every day,
 * so wiring it in changes no pesos until HR flips a mode.
 *
 * Coordination with approved early-out requests: those are priced elsewhere
 * (unpaidDaysFor's earlyOutFraction, ÷ scheduled span) and left untouched to preserve
 * the no-op. The caller passes `governed_by_early_out = true` for such days and this
 * service returns zero undertime for them, so the two paths can never double-charge.
 */
class DeductionService
{
    /**
     * @param  array<string,mixed>  $ctx  the day's facts (all times as Manila minute-of-day ints):
     *   - start_min, end_min: int|null           schedule window
     *   - grace_min: int                          resolved grace (via effectiveFor → daily overrides, F-8)
     *   - time_in_min: int|null                   clock-in (null ⇒ not a worked day; returns zero)
     *   - time_out_min: int|null                  clock-out (null ⇒ missing punch; see missing_clockout_mode)
     *   - half_day_off: 'AM'|'PM'|null            which half is off (worked window is the OTHER half)
     *   - am_start_min, am_end_min, pm_start_min, pm_end_min: int|null   half windows (F-3)
     *   - governed_by_early_out: bool             an approved early-out already prices this day's undertime
     *   - amnesty: bool                           force-majeure waiver for this date (F-8/§9) ⇒ zero
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

        $flags = $this->breakFlags($ctx, $policy);

        // --- the worked window (a half-day shifts start/end to the worked half) ---
        [$workStart, $workEnd] = $this->workedWindow($ctx);

        // --- late ---
        $lateMode = $policy->late_deduction_mode;
        $lateMinutes = 0;
        if ($workStart !== null && ($lateMode === 'per_hour' || $lateMode === 'tiered')) {
            $effectiveStart = $policy->late_minutes_from === 'after_grace'
                ? $workStart + (int) ($ctx['grace_min'] ?? 0)
                : $workStart;
            $lateMinutes = max(0, $timeIn - $effectiveStart);
        }

        // --- undertime (clock-derived; approved early-out days are handled elsewhere) ---
        $undertimeMinutes = 0;
        $timeOut = $ctx['time_out_min'] ?? null;
        if (
            $policy->undertime_deduction_mode === 'per_hour'
            && empty($ctx['governed_by_early_out'])
            && $workEnd !== null
            && $timeOut !== null // a missing clock-out is flagged, not charged (default mode)
        ) {
            $undertimeMinutes = max(0, $workEnd - $timeOut);
        }
        if ($timeOut === null) {
            $flags[] = 'missing_clockout';
        }

        // --- convert charged minutes → billable minutes → fraction of a day ---
        $chargedLate = ($lateMode === 'tiered')
            ? $this->tierMinutes($lateMinutes, $policy)
            : $lateMinutes;

        $lateFraction = $this->toFraction($chargedLate, $policy);
        $undertimeFraction = $this->toFraction($undertimeMinutes, $policy);

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
     * half-day, the half they were NOT off (F-3). Falls back to the full window when the
     * half-day times aren't configured, so it stays a no-op until HR fills them in.
     *
     * @param  array<string,mixed>  $ctx
     * @return array{0:int|null,1:int|null}  [start_min, end_min]
     */
    private function workedWindow(array $ctx): array
    {
        $off = $ctx['half_day_off'] ?? null;

        if ($off === 'AM' && isset($ctx['pm_start_min'], $ctx['pm_end_min'])) {
            return [$ctx['pm_start_min'], $ctx['pm_end_min']];
        }
        if ($off === 'PM' && isset($ctx['am_start_min'], $ctx['am_end_min'])) {
            return [$ctx['am_start_min'], $ctx['am_end_min']];
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

    /**
     * Break/lunch overage is FLAGGED for HR, never charged (2026-07-21 revision). A day
     * whose recorded break exceeds the policy cap is a suspected forgotten punch (F-6).
     *
     * @param  array<string,mixed>  $ctx
     * @return list<string>
     */
    private function breakFlags(array $ctx, AttendancePolicy $policy): array
    {
        $flags = [];
        $break = (int) ($ctx['break_minutes'] ?? 0);
        $lunch = (int) ($ctx['lunch_minutes'] ?? 0);

        if ($break + $lunch > (int) $policy->max_break_minutes_per_day) {
            $flags[] = 'long_break';
        }

        return $flags;
    }
}
