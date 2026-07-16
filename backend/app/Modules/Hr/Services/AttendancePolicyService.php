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

    public function get(): AttendancePolicy
    {
        return AttendancePolicy::firstOrCreate([], [
            'consecutive_lates_before_absence' => 3,
            'monthly_late_reset' => true,
            'awol_days_threshold' => 3,
            'late_deduction_mode' => 'none',
            'lates_per_deduction' => 3,
            'task_format' => ['require_english' => true, 'uppercase' => true],
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): AttendancePolicy
    {
        $policy = $this->get();
        $policy->update([
            'consecutive_lates_before_absence' => $data['consecutive_lates_before_absence'] ?? $policy->consecutive_lates_before_absence,
            'monthly_late_reset' => $data['monthly_late_reset'] ?? $policy->monthly_late_reset,
            'awol_days_threshold' => $data['awol_days_threshold'] ?? $policy->awol_days_threshold,
            'late_deduction_mode' => $data['late_deduction_mode'] ?? $policy->late_deduction_mode,
            'lates_per_deduction' => $data['lates_per_deduction'] ?? $policy->lates_per_deduction,
            'task_format' => $data['task_format'] ?? $policy->task_format,
        ]);

        $this->audit->log('updated', 'Rules', 'Attendance rules updated', $policy);

        return $policy;
    }
}
