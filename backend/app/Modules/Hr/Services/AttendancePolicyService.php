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
    private const DEFAULTS = [
        'consecutive_lates_before_absence' => 3,
        'monthly_late_reset' => true,
        'awol_days_threshold' => 3,
        'late_deduction_mode' => 'none',
        'lates_per_deduction' => 3,
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
        'task_format' => ['require_english' => true, 'uppercase' => true],
    ];

    public function get(): AttendancePolicy
    {
        return AttendancePolicy::firstOrCreate([], self::DEFAULTS);
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
