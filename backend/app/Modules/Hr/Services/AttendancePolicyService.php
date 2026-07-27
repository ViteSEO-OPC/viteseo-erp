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
        'overtime_mode' => 'approved_only',
        'overtime_threshold_minutes' => 0,
        'deduction_rounding' => 'exact',
        'deduction_block_minutes' => 60,
        'daily_deduction_cap' => 1.0,
        'daily_rate_basis' => 'period_workdays',
        'annual_divisor' => 261,
        'hours_per_day_divisor' => 8,
        'max_break_minutes_per_day' => 120,
        'over_max_break_action' => 'flag_only',
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
    ];

    public function get(): AttendancePolicy
    {
        return AttendancePolicy::firstOrCreate([], self::DEFAULTS);
    }

    /**
     * The violation colour ladder, with a null column meaning "today's behaviour".
     * ViolationService should read the ladder through HERE, never off the column, so a
     * policy row predating the feature can never yield an empty ladder (= no flags at all).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function violationRules(): array
    {
        return $this->get()->violation_rules ?: self::DEFAULT_VIOLATION_RULES;
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
        $policy->update($changes);

        $this->audit->log('updated', 'Rules', 'Attendance rules updated', $policy);

        return $policy;
    }
}
