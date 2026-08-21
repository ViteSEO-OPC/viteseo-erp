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
            'late_tier_recurring' => (bool) $this->late_tier_recurring,

            'undertime_deduction_mode' => $this->undertime_deduction_mode,
            'missing_clockout_mode' => $this->missing_clockout_mode,
            'missing_timeout_allowance_minutes' => (int) ($this->missing_timeout_allowance_minutes ?? 180),

            'early_arrival_mode' => $this->early_arrival_mode,
            'early_clock_in_mode' => $this->early_clock_in_mode,
            'rest_day_work_requires_request' => (bool) $this->rest_day_work_requires_request,
            'management_auto_attendance' => (bool) $this->management_auto_attendance,
            'management_holiday_premium' => (bool) $this->management_holiday_premium,

            // Geofenced clock-in. lat/lng stay nullable — "no pin set yet" is a real state
            // the Rules form has to distinguish from 0,0 (a valid point in the Atlantic).
            'geofence_enabled' => (bool) $this->geofence_enabled,
            'office_lat' => $this->office_lat,
            'office_lng' => $this->office_lng,
            'office_radius_m' => (int) $this->office_radius_m,
            'office_ips' => $this->office_ips ?? [],
            // Read-only, never persisted: the IP the SERVER sees this caller as. It is both
            // the easy way for HR to fill the list in (open Rules from the office, click Add)
            // and the diagnostic for the trusted-proxy gotcha — if every office machine shows
            // the same 127.0.0.1 or proxy address here, `trustProxies` isn't configured yet
            // and the IP half of the check would pass for the whole internet.
            'your_ip' => $request->ip(),

            'overtime_mode' => $this->overtime_mode,
            'overtime_threshold_minutes' => (int) $this->overtime_threshold_minutes,
            // 0 = uncapped. See the migration for why that is the default.
            'auto_overtime_daily_cap_minutes' => (int) $this->auto_overtime_daily_cap_minutes,
            'auto_overtime_period_cap_minutes' => (int) $this->auto_overtime_period_cap_minutes,
            'auto_overtime_review_minutes' => (int) ($this->auto_overtime_review_minutes ?? 240),

            'premium_rest_day_pct' => (int) $this->premium_rest_day_pct,
            'premium_special_day_pct' => (int) $this->premium_special_day_pct,
            'premium_regular_holiday_pct' => (int) $this->premium_regular_holiday_pct,
            'overtime_ordinary_pct' => (int) $this->overtime_ordinary_pct,
            'overtime_rest_special_pct' => (int) $this->overtime_rest_special_pct,
            'overtime_regular_holiday_pct' => (int) $this->overtime_regular_holiday_pct,
            'night_diff_pct' => (int) $this->night_diff_pct,
            // MySQL TIME values include seconds; Company → Rules edits minute precision.
            'night_diff_start_time' => substr((string) ($this->night_diff_start_time ?: '22:00'), 0, 5),
            'night_diff_end_time' => substr((string) ($this->night_diff_end_time ?: '06:00'), 0, 5),

            'deduction_rounding' => $this->deduction_rounding,
            'deduction_block_minutes' => (int) $this->deduction_block_minutes,
            'daily_deduction_cap' => (float) $this->daily_deduction_cap,

            'daily_rate_basis' => $this->daily_rate_basis,
            'annual_divisor' => (int) $this->annual_divisor,
            'hours_per_day_divisor' => (int) $this->hours_per_day_divisor,

            'lunch_grace_minutes' => (int) $this->lunch_grace_minutes,
            'break_grace_minutes' => (int) $this->break_grace_minutes,
            'violation_rules' => $this->violation_rules,

            'task_format' => $this->task_format,
        ];
    }
}
