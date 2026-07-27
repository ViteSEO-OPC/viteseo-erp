<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendancePolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'monthly_late_reset' => (bool) $this->monthly_late_reset,
            'late_deduction_mode' => $this->late_deduction_mode,
            'lates_per_deduction' => (int) $this->lates_per_deduction,
            'late_penalty_enabled' => (bool) $this->late_penalty_enabled,
            'late_minutes_from' => $this->late_minutes_from,
            'late_tiers' => $this->late_tiers,

            'undertime_deduction_mode' => $this->undertime_deduction_mode,
            'missing_clockout_mode' => $this->missing_clockout_mode,

            'early_arrival_mode' => $this->early_arrival_mode,
            'overtime_mode' => $this->overtime_mode,
            'overtime_threshold_minutes' => (int) $this->overtime_threshold_minutes,

            'deduction_rounding' => $this->deduction_rounding,
            'deduction_block_minutes' => (int) $this->deduction_block_minutes,
            'daily_deduction_cap' => (float) $this->daily_deduction_cap,

            'daily_rate_basis' => $this->daily_rate_basis,
            'annual_divisor' => (int) $this->annual_divisor,
            'hours_per_day_divisor' => (int) $this->hours_per_day_divisor,

            'max_break_minutes_per_day' => (int) $this->max_break_minutes_per_day,
            'over_max_break_action' => $this->over_max_break_action,
            'violation_rules' => $this->violation_rules,

            'task_format' => $this->task_format,
        ];
    }
}
