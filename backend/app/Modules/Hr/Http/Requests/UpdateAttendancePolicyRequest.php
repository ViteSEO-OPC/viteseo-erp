<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendancePolicyRequest extends FormRequest
{
    /** Severity order for the monotonic-threshold guard. */
    private const SEVERITY_RANK = ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monthly_late_reset' => ['sometimes', 'boolean'],
            // Per-time charging only. The per-N-lates penalty is now its own independent
            // switch (`late_penalty_enabled`), so the two can be combined.
            'late_deduction_mode' => ['sometimes', 'in:none,per_hour,tiered'],
            'late_penalty_enabled' => ['sometimes', 'boolean'],
            'lates_per_deduction' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'late_minutes_from' => ['sometimes', 'in:start_time,after_grace'],
            'late_tiers' => ['sometimes', 'nullable', 'array'],
            'late_tiers.*.over' => ['required_with:late_tiers', 'integer', 'min:0', 'max:1440'],
            'late_tiers.*.hours' => ['required_with:late_tiers', 'numeric', 'min:0', 'max:24'],

            'undertime_deduction_mode' => ['sometimes', 'in:none,per_hour'],
            'missing_clockout_mode' => ['sometimes', 'in:no_deduction_flag_for_review,treat_as_undertime_to_now,treat_as_absent'],

            'early_arrival_mode' => ['sometimes', 'in:none,offset_undertime,overtime_eligible'],
            'overtime_mode' => ['sometimes', 'in:none,approved_only,auto_after_threshold'],
            'overtime_threshold_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],

            // Premium & overtime rates (Premium Pay epic, Phase 0). Whole-percent multipliers of the
            // hourly/daily rate; each HARD-FLOORED at its DOLE minimum (editable UP, never below).
            // night_diff_pct is the add-on percent (min +10%).
            'premium_rest_day_pct' => ['sometimes', 'integer', 'min:130', 'max:1000'],
            'premium_special_day_pct' => ['sometimes', 'integer', 'min:130', 'max:1000'],
            'premium_regular_holiday_pct' => ['sometimes', 'integer', 'min:200', 'max:1000'],
            'overtime_ordinary_pct' => ['sometimes', 'integer', 'min:125', 'max:1000'],
            'overtime_rest_special_pct' => ['sometimes', 'integer', 'min:169', 'max:1000'],
            'overtime_regular_holiday_pct' => ['sometimes', 'integer', 'min:260', 'max:1000'],
            'night_diff_pct' => ['sometimes', 'integer', 'min:10', 'max:100'],

            // `exact` is the legally safe default; the round_* modes are a warned opt-in (§9).
            'deduction_rounding' => ['sometimes', 'in:exact,round_up,round_nearest,round_down'],
            'deduction_block_minutes' => ['sometimes', 'integer', 'min:1', 'max:480'],
            'daily_deduction_cap' => ['sometimes', 'numeric', 'min:0', 'max:1'],

            'daily_rate_basis' => ['sometimes', 'in:period_workdays,fixed_divisor'],
            // Free number with 261/313/365 as the usual presets — not restricted to them.
            'annual_divisor' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'hours_per_day_divisor' => ['sometimes', 'integer', 'min:1', 'max:24'],

            // 0 is meaningful: no tolerance at all.
            'lunch_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'break_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],

            // Violation colour ladder (step 3c). Rungs are evaluated top-down, first match
            // wins. Severity names are fixed — Violation::SEVERITY_RANK depends on them.
            'violation_rules' => ['sometimes', 'array'],
            'violation_rules.late' => ['sometimes', 'array'],
            'violation_rules.absence' => ['sometimes', 'array'],
            'violation_rules.missed_time_out' => ['sometimes', 'array'],
            'violation_rules.*.*.severity' => ['required', 'in:green,yellow,orange,red'],
            'violation_rules.*.*.streak_min' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'violation_rules.*.*.monthly_min' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'violation_rules.*.*.occurrence_min' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'violation_rules.*.*.type' => ['sometimes', 'string', 'max:40'],

            'task_format' => ['sometimes', 'array'],
            'task_format.require_english' => ['sometimes', 'boolean'],
            'task_format.uppercase' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The alert levels must strictly ascend: a higher-severity level can't trigger at the
     * same or a lower threshold than a milder one (e.g. Low 2 ⇒ Caution must be > 2).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach (['late', 'absence', 'missed_time_out'] as $category) {
                $rungs = $this->input("violation_rules.{$category}");
                if (! is_array($rungs)) {
                    continue;
                }

                // (severity rank → threshold) for each rung, then check ascending.
                $byRank = [];
                foreach ($rungs as $rung) {
                    $rank = self::SEVERITY_RANK[$rung['severity'] ?? ''] ?? null;
                    $threshold = $rung['monthly_min'] ?? $rung['streak_min'] ?? $rung['occurrence_min'] ?? null;
                    if ($rank !== null && $threshold !== null) {
                        $byRank[$rank] = (int) $threshold;
                    }
                }
                ksort($byRank);

                $prev = null;
                foreach ($byRank as $threshold) {
                    if ($prev !== null && $threshold <= $prev) {
                        $v->errors()->add(
                            "violation_rules.{$category}",
                            'Each alert level must use a higher number than the level below it.',
                        );
                        break;
                    }
                    $prev = $threshold;
                }
            }
        });
    }
}
