<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendancePolicy;

/**
 * The single attendance-policy row (Company → Rules). Slice 16's violation engine
 * reads these thresholds; the task-format rule governs the time-in task checklist.
 */
class AttendancePolicyService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Every editable field, with its current-behaviour default. The deduction-policy
     * fields (late_minutes_from onward) all seed to today's behaviour, so a fresh
     * install computes exactly the same pesos until HR opts into a mode.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'monthly_late_reset' => true,
        'late_deduction_mode' => 'none',
        'lates_per_deduction' => 3,
        'late_penalty_enabled' => false,
        'late_minutes_from' => 'start_time',
        'late_tiers' => null,
        'undertime_deduction_mode' => 'none',
        'missing_clockout_mode' => 'no_deduction_flag_for_review',
        'early_arrival_mode' => 'none',
        // Off = today's behaviour: an early punch is accepted and recorded as-is.
        'block_early_clock_in' => false,
        'overtime_mode' => 'approved_only',
        'overtime_threshold_minutes' => 0,
        // Premium & overtime rates (Phase 0) — seeded to the DOLE legal minimums (no-op).
        'premium_rest_day_pct' => 130,
        'premium_special_day_pct' => 130,
        'premium_regular_holiday_pct' => 200,
        'overtime_ordinary_pct' => 125,
        'overtime_rest_special_pct' => 169,
        'overtime_regular_holiday_pct' => 260,
        'night_diff_pct' => 10,
        'deduction_rounding' => 'exact',
        'deduction_block_minutes' => 60,
        'daily_deduction_cap' => 1.0,
        'daily_rate_basis' => 'period_workdays',
        'annual_divisor' => 261,
        'hours_per_day_divisor' => 8,
        // Slice 9 — how far over the SCHEDULE's own allowance each may run before it is
        // flagged. The allowances themselves live on the work schedule; lunch and break are
        // judged separately so a short lunch can't bankroll a long break. 0 = no tolerance.
        'lunch_grace_minutes' => 15,
        'break_grace_minutes' => 15,
        'violation_rules' => self::DEFAULT_VIOLATION_RULES,
        'task_format' => ['require_english' => true, 'uppercase' => true],
    ];

    /**
     * The violation colour ladder (step 3c / finding P1-5). These values are TODAY'S
     * hard-coded literals from ViolationService::recompute() (:167-168, :183, :186, :196),
     * lifted into data so HR can change them — seeding them here keeps the engine's
     * behaviour identical until ViolationService is taught to read this column.
     *
     * Evaluated TOP-DOWN, first match wins (so list most-severe first). An empty list for
     * a category means "never flag it". Severity names must stay green|yellow|orange|red —
     * Violation::SEVERITY_RANK and the UI badges depend on them.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    public const DEFAULT_VIOLATION_RULES = [
        // Four ascending alert levels, matching the Attendance modal legend
        // (green Low → yellow Caution → orange Warning → red Critical). Lateness is by
        // count of lates this month; absence by consecutive days. Evaluated top-down.
        'late' => [
            ['monthly_min' => 6, 'severity' => 'red'],
            ['monthly_min' => 4, 'severity' => 'orange'],
            ['monthly_min' => 3, 'severity' => 'yellow'],
            ['monthly_min' => 1, 'severity' => 'green'],
        ],
        'absence' => [
            ['streak_min' => 4, 'severity' => 'red', 'type' => 'awol'],
            ['streak_min' => 3, 'severity' => 'orange', 'type' => 'ua'],
            ['streak_min' => 2, 'severity' => 'yellow', 'type' => 'ua'],
            ['streak_min' => 1, 'severity' => 'green', 'type' => 'ua'],
        ],
        // Missed clock-outs this month — a count ladder, like `late` (HR-configurable, step B).
        'missed_time_out' => [
            ['occurrence_min' => 4, 'severity' => 'red'],
            ['occurrence_min' => 3, 'severity' => 'orange'],
            ['occurrence_min' => 2, 'severity' => 'yellow'],
            ['occurrence_min' => 1, 'severity' => 'green'],
        ],
        // Slice 9 — days this month where lunch OR break ran past its own schedule allowance
        // by more than its grace. Flag only: an over-long break NEVER costs pay.
        'long_break' => [
            ['occurrence_min' => 4, 'severity' => 'red'],
            ['occurrence_min' => 3, 'severity' => 'orange'],
            ['occurrence_min' => 2, 'severity' => 'yellow'],
            ['occurrence_min' => 1, 'severity' => 'green'],
        ],
    ];

    /**
     * Per-request memo (Slice 15). This single row is read by every pricing path, once per
     * employee per payroll run. update() clears it.
     */
    private ?AttendancePolicy $memo = null;

    public function get(): AttendancePolicy
    {
        return $this->memo ??= AttendancePolicy::firstOrCreate([], self::DEFAULTS);
    }

    /**
     * The violation colour ladder, with a null column meaning "today's behaviour".
     * ViolationService should read the ladder through HERE, never off the column, so a
     * policy row predating the feature can never yield an empty ladder (= no flags at all).
     *
     * A stored ladder takes priority per CATEGORY, with defaults filling any category it
     * doesn't mention — so adding a category (Slice 9's `long_break`) works immediately
     * instead of being silently absent for every org that has ever saved this page.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function violationRules(): array
    {
        return array_merge(self::DEFAULT_VIOLATION_RULES, $this->get()->violation_rules ?: []);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): AttendancePolicy
    {
        $policy = $this->get();

        // Only the known fields, and only when supplied — an absent key keeps its value.
        $changes = [];
        foreach (array_keys(self::DEFAULTS) as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        // Item 13b.12 — record WHAT changed. This was one line reading "Attendance rules
        // updated" with no before/after, which is the single audit entry guarding every
        // money-moving rule in the module: it could not answer "who changed the overtime rate?".
        $before = [];
        $after = [];
        foreach ($changes as $field => $value) {
            $old = $policy->getOriginal($field);
            if ($old != $value) {
                $before[$field] = $old;
                $after[$field] = $value;
            }
        }

        $policy->update($changes);
        $this->memo = $policy; // keep the memo in step with what was just written

        $this->audit->log(
            'updated',
            'Rules',
            $before === []
                ? 'Attendance rules saved (no changes)'
                : 'Attendance rules updated: ' . implode(', ', array_keys($before)),
            $policy,
            $before ?: null,
            $after ?: null,
        );

        return $policy;
    }
}
